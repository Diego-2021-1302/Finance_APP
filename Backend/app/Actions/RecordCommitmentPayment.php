<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\CommitmentOccurrence;
use App\Models\FinancialTransaction;
use App\Models\Commitment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordCommitmentPayment
{
    public function execute(User $user, CommitmentOccurrence $occurrence, array $input, RecalculateCommitmentAmortization $amortization): array
    {
        return DB::transaction(function () use ($user, $occurrence, $input, $amortization) {
            $existing = Payment::query()->where('user_id', $user->id)->where('idempotency_key', $input['idempotency_key'])->first();
            if ($existing) {
                if ((int) $existing->commitment_occurrence_id !== (int) $occurrence->id || (int) $existing->account_id !== (int) $input['account_id'] || bccomp((string) $existing->amount, (string) $input['amount'], 2) !== 0) {
                    abort(409, 'La clave de idempotencia ya fue utilizada para una operación diferente.');
                }
                return ['payment' => $existing, 'remaining_due' => $this->remainingDue($occurrence)];
            }

            $account = Account::query()->where('user_id', $user->id)->whereKey($input['account_id'])->lockForUpdate()->firstOrFail();
            $commitment = Commitment::query()->where('user_id', $user->id)->whereKey($occurrence->commitment_id)->lockForUpdate()->firstOrFail();
            $remaining = $this->remainingDue($occurrence);
            $isAmortized = in_array($commitment->type, ['loan', 'credit_card'], true);
            if (!$isAmortized && bccomp((string) $input['amount'], $remaining, 2) === 1) {
                throw ValidationException::withMessages(['amount' => 'El pago excede el monto restante a vencer.']);
            }
            if ($isAmortized) {
                $periodInterest = $occurrence->interest_accrued
                    ?? $amortization->interestFor((string) $commitment->current_balance, (string) $commitment->interest_rate, $commitment->frequency);
                $periodPaid = (string) $occurrence->payments()->whereNull('reversed_at')->sum('amount');
                $debtRemaining = bcsub(
                    bcadd((string) $commitment->current_balance, $periodInterest, 2),
                    $periodPaid,
                    2,
                );
                if (bccomp((string) $input['amount'], $debtRemaining, 2) === 1) {
                    throw ValidationException::withMessages(['amount' => 'El pago excede el saldo del préstamo o tarjeta incluyendo los intereses de este período.']);
                }
            }
            $payment = Payment::query()->create([
                'user_id' => $user->id, 'commitment_occurrence_id' => $occurrence->id, 'account_id' => $account->id,
                'amount' => $input['amount'], 'paid_at' => $input['paid_at'], 'idempotency_key' => $input['idempotency_key'],
            ]);
            FinancialTransaction::query()->create([
                'user_id' => $user->id, 'account_id' => $account->id, 'payment_id' => $payment->id,
                'description' => $occurrence->commitment->name, 'amount' => bcsub('0.00', (string) $input['amount'], 2),
                'occurred_at' => $input['paid_at'], 'kind' => 'commitment_payment',
            ]);
            $newRemaining = bccomp((string) $input['amount'], $remaining, 2) >= 0
                ? '0.00'
                : bcsub($remaining, (string) $input['amount'], 2);
            $amortization->execute($commitment);
            $commitment->refresh();
            $isAmortized = in_array($commitment->type, ['loan', 'credit_card'], true);
            $balancePaidOff = $isAmortized && bccomp((string) $commitment->current_balance, '0.00', 2) === 0;
            if ($balancePaidOff) {
                $newRemaining = '0.00';
                $occurrence->update(['status' => 'paid']);
                $commitment->update(['is_active' => false]);
            }
            if (!$balancePaidOff && bccomp((string) $input['amount'], $remaining, 2) >= 0) {
                $occurrence->update(['status' => 'paid']);
                $nextDueDate = $this->nextDueDate($occurrence->due_date, $commitment->frequency);
                if ($nextDueDate !== null) {
                    $commitment->update(['next_due_date' => $nextDueDate]);
                    CommitmentOccurrence::query()->firstOrCreate(
                        ['commitment_id' => $commitment->id, 'due_date' => $nextDueDate, 'schedule_version' => 1],
                        ['user_id' => $user->id, 'amount_due' => $commitment->amount, 'status' => 'scheduled'],
                    );
                }
            }
            return ['payment' => $payment, 'remaining_due' => $newRemaining];
        }, 3);
    }

    private function remainingDue(CommitmentOccurrence $occurrence): string
    {
        $paid = Payment::query()->where('commitment_occurrence_id', $occurrence->id)->whereNull('reversed_at')->sum('amount');
        return bcsub((string) $occurrence->amount_due, (string) $paid, 2);
    }

    private function nextDueDate(Carbon $dueDate, string $frequency): ?Carbon
    {
        $next = $dueDate->copy();
        return match ($frequency) {
            'weekly' => $next->addWeek(),
            'biweekly' => $next->addWeeks(2),
            'monthly' => $next->addMonthNoOverflow(),
            'quarterly' => $next->addMonthsNoOverflow(3),
            'yearly' => $next->addYearNoOverflow(),
            default => null,
        };
    }
}
