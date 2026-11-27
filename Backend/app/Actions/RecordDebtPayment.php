<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\Debt;
use App\Models\DebtBalanceMovement;
use App\Models\FinancialTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordDebtPayment
{
    public function execute(User $user, Debt $debt, array $input): array
    {
        return DB::transaction(function () use ($user, $debt, $input) {
            $existing = DebtBalanceMovement::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $input['idempotency_key'])
                ->first();

            if ($existing) {
                if ((int) $existing->debt_id !== (int) $debt->id
                    || $existing->kind !== 'payment'
                    || (int) ($existing->account_id ?? 0) !== (int) ($input['account_id'] ?? 0)
                    || bccomp((string) $existing->delta, bcsub('0.00', (string) ($input['amount'] ?? '0.00'), 2), 2) !== 0
                    || !$existing->occurred_at->equalTo(Carbon::parse($input['paid_at']))) {
                    abort(409, 'La clave de idempotencia ya fue utilizada para una operación diferente.');
                }

                return [
                    'debt' => Debt::query()->where('user_id', $user->id)->whereKey($debt->id)->firstOrFail(),
                    'movement' => $existing,
                    'created' => false,
                ];
            }

            $record = Debt::query()->where('user_id', $user->id)->whereKey($debt->id)->lockForUpdate()->firstOrFail();
            $account = Account::query()->where('user_id', $user->id)->where('is_active', true)->whereKey($input['account_id'])->lockForUpdate()->firstOrFail();

            $paidAt = Carbon::parse($input['paid_at']);
            $paymentAmount = (string) $input['amount'];

            if ($record->kind === 'credit_card') {
                try {
                    $calculated = \App\Services\CardPaymentCalculator::calculate(
                        (string) $record->outstanding_amount,
                        (string) ($record->statement_balance ?? '0.00'),
                        (string) ($record->minimum_payment ?? '0.00'),
                        $input['payment_type'] ?? 'custom',
                        (string) $input['amount']
                    );
                } catch (\InvalidArgumentException $exception) {
                    throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
                }

                $paymentAmount = $calculated['amount'];
                $record->update([
                    'outstanding_amount' => $calculated['outstanding'],
                    'statement_balance' => $calculated['statement_balance'],
                ]);

                $statement = \App\Models\CreditCardStatement::query()
                    ->where('user_id', $user->id)
                    ->where('debt_id', $record->id)
                    ->orderByDesc('statement_date')
                    ->first();
                if ($statement) {
                    $statement->update(['closing_balance' => $calculated['statement_balance']]);
                }
            } elseif (bccomp($paymentAmount, (string) $record->outstanding_amount, 2) === 1) {
                throw ValidationException::withMessages(['amount' => 'El pago no puede exceder el saldo pendiente.']);
            }

            $movement = DebtBalanceMovement::query()->create([
                'user_id' => $user->id,
                'debt_id' => $record->id,
                'account_id' => $account->id,
                'kind' => 'payment',
                'delta' => bcsub('0.00', $paymentAmount, 2),
                'description' => 'Pago de deuda',
                'occurred_at' => $paidAt,
                'idempotency_key' => $input['idempotency_key'],
            ]);
            $newOutstanding = bcsub((string) $record->outstanding_amount, $paymentAmount, 2);
            $loanUpdates = ['outstanding_amount' => $newOutstanding];

            if ($record->kind === 'loan') {
                $installment = (string) ($record->minimum_payment ?? '0.00');
                $paymentCompletesInstallment = bccomp($paymentAmount, $installment, 2) >= 0;

                if ($paymentCompletesInstallment && bccomp($newOutstanding, '0.00', 2) > 0) {
                    $currentDue = $record->due_date
                        ? \Illuminate\Support\Carbon::parse($record->due_date)->startOfDay()
                        : now()->startOfDay();
                    $nextDue = $record->amortization_type === 'biweekly'
                        ? $currentDue->copy()->addDays(15)
                        : \App\Services\LoanPaymentSchedule::addMonthKeepingDay($currentDue);

                    $maturity = $record->maturity_date
                        ? \Illuminate\Support\Carbon::parse($record->maturity_date)->startOfDay()
                        : null;
                    if ($maturity && $nextDue->gt($maturity)) {
                        $nextDue = $maturity;
                    }
                    $loanUpdates['due_date'] = $nextDue->toDateString();

                    $schedule = \App\Services\LoanPaymentSchedule::remainingInstallments(
                        now(),
                        $maturity ?? now()->copy()->addYear(),
                        (string) ($record->amortization_type ?? 'monthly'),
                        $newOutstanding,
                        $installment
                    );
                    $loanUpdates['term_months'] = $schedule['months_remaining'];
                }

                if (bccomp($newOutstanding, '0.00', 2) <= 0) {
                    $loanUpdates['outstanding_amount'] = '0.00';
                    $loanUpdates['due_date'] = $record->maturity_date?->toDateString() ?? $record->due_date?->toDateString();
                    $loanUpdates['term_months'] = 0;
                }
            }

            if ($record->kind !== 'credit_card') {
                $record->update($loanUpdates);
            }
            FinancialTransaction::query()->create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'description' => 'Abono a tarjeta / deuda: '.$record->name,
                'amount' => bcsub('0.00', $paymentAmount, 2),
                'occurred_at' => $paidAt,
                'kind' => 'debt_payment',
            ]);

            // If debt has a linked Credit Card account, credit that credit card account
            $cardAccount = Account::query()
                ->where('user_id', $user->id)
                ->where(function ($query) use ($record) {
                    $query->where('id', $record->account_id)
                        ->orWhere('name', $record->name);
                })
                ->first();

            if ($cardAccount && $cardAccount->id !== $account->id) {
                FinancialTransaction::query()->create([
                    'user_id' => $user->id,
                    'account_id' => $cardAccount->id,
                    'description' => sprintf('Abono recibido desde %s', $account->name),
                    'amount' => $paymentAmount,
                    'occurred_at' => $paidAt,
                    'kind' => 'transfer_in',
                ]);
            }

            return ['debt' => $record->refresh(), 'movement' => $movement, 'created' => true];
        }, 3);
    }
}
