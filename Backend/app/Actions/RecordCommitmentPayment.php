<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\CommitmentOccurrence;
use App\Models\FinancialTransaction;
use App\Models\Commitment;
use App\Models\Debt;
use App\Models\DebtBalanceMovement;
use App\Models\Payment;
use App\Models\User;
use App\Services\CommitmentRecurrence;
use App\Services\DebtBalanceLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordCommitmentPayment
{
    public function __construct(
        private readonly CommitmentRecurrence $recurrence,
        private readonly DebtBalanceLedger $balanceLedger,
    ) {}

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
            $paidAt = Carbon::parse($input['paid_at']);
            FinancialTransaction::query()->create([
                'user_id' => $user->id, 'account_id' => $account->id, 'payment_id' => $payment->id,
                'description' => $occurrence->commitment->name, 'amount' => bcsub('0.00', (string) $input['amount'], 2),
                'occurred_at' => $paidAt, 'kind' => 'commitment_payment',
            ]);
            if ($commitment->type === 'credit_card') {
                $debt = Debt::query()
                    ->where('user_id', $user->id)
                    ->where('kind', 'credit_card')
                    ->where('name', $commitment->name)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->balanceLedger->ensureOpeningBalance($debt, $debt->account_id, $paidAt);

                DebtBalanceMovement::query()->create([
                    'user_id' => $user->id,
                    'debt_id' => $debt->id,
                    'payment_id' => $payment->id,
                    'account_id' => $account->id,
                    'kind' => 'payment',
                    'delta' => bcsub('0.00', (string) $input['amount'], 2),
                    'description' => 'Credit card commitment payment',
                    'occurred_at' => $paidAt,
                    'idempotency_key' => Str::uuid()->toString(),
                ]);
                $debt->update([
                    'outstanding_amount' => bcsub((string) $debt->outstanding_amount, (string) $input['amount'], 2),
                ]);
            }
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
                $firstDueDate = CommitmentOccurrence::query()
                    ->where('commitment_id', $commitment->id)
                    ->orderBy('due_date')
                    ->orderBy('id')
                    ->value('due_date');
                $baseDay = Carbon::parse($firstDueDate ?? $occurrence->due_date)->day;
                $nextDueDate = $this->recurrence->nextDueDate($occurrence->due_date, $commitment->frequency, $baseDay);
                if ($nextDueDate !== null) {
                    $commitment->update(['next_due_date' => $nextDueDate]);
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

}
