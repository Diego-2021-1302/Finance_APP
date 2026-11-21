<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Expense;
use App\Models\FinancialTransaction;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function recordExpense(User $user, array $input): array
    {
        return DB::transaction(function () use ($user, $input) {
            $existing = Expense::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $input['idempotency_key'])
                ->first();

            if ($existing) {
                if ((int) $existing->account_id !== (int) $input['account_id']
                    || bccomp((string) $existing->amount, (string) $input['amount'], 2) !== 0) {
                    abort(409, 'La clave de idempotencia ya fue utilizada para una operación diferente.');
                }

                $account = Account::query()->where('user_id', $user->id)->whereKey($existing->account_id)->firstOrFail();
                return ['expense' => $existing, 'account' => $account, 'created' => false];
            }

            $account = Account::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->whereKey($input['account_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($account->type !== 'credit_card') {
                $currentBalance = (string) ($account->transactions()->sum('amount') ?: '0.00');
                if (bccomp($currentBalance, (string) $input['amount'], 2) < 0) {
                    $formattedBalance = number_format((float) $currentBalance, 2, '.', ',');
                    throw ValidationException::withMessages([
                        'account_id' => [sprintf('La cuenta "%s" no tiene fondos suficientes para este gasto. (Balance disponible: RD$ %s)', $account->name, $formattedBalance)],
                    ]);
                }
            }

            $occurredAt = Carbon::parse($input['occurred_at']);

            $expense = Expense::query()->create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'category' => $input['category'],
                'amount' => $input['amount'],
                'occurred_at' => $occurredAt,
                'description' => $input['description'],
                'reference_number' => $input['reference_number'] ?? null,
                'idempotency_key' => $input['idempotency_key'],
            ]);

            FinancialTransaction::query()->create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'description' => sprintf('%s: %s', $input['category'], $input['description']),
                'amount' => bcsub('0.00', (string) $input['amount'], 2),
                'occurred_at' => $occurredAt,
                'kind' => 'expense',
            ]);

            // If account is a credit card, authorize transaction and sync debt/overdraft movements
            if ($account->type === 'credit_card') {
                $debt = \App\Models\Debt::query()
                    ->where('user_id', $user->id)
                    ->where(function ($query) use ($account) {
                        if ($account->debt_id) {
                            $query->where('id', $account->debt_id);
                        } else {
                            $query->where('account_id', $account->id)
                                ->orWhere('name', $account->name);
                        }
                    })
                    ->lockForUpdate()
                    ->first();

                if (!$debt) {
                    $debt = \App\Models\Debt::query()->create([
                        'user_id' => $user->id,
                        'account_id' => $account->id,
                        'name' => $account->name,
                        'kind' => 'credit_card',
                        'outstanding_amount' => '0.00',
                        'minimum_payment' => '0.00',
                        'minimum_payment_confirmed' => false,
                        'approved_credit_limit' => $account->approved_credit_limit ?? $account->credit_limit,
                        'authorized_overdraft_limit' => $account->authorized_overdraft_limit ?? '0.00',
                        'limit_status' => ($account->limit_status && $account->limit_status !== 'unknown') ? $account->limit_status : 'confirmed',
                        'overdraft_fee_type' => $account->overdraft_fee_type ?? 'none',
                        'overdraft_fee_amount' => $account->overdraft_fee_amount ?? '0.00',
                        'overdraft_fee_percentage' => $account->overdraft_fee_percentage ?? '0.00',
                        'overdraft_fee_tax_rate' => $account->overdraft_fee_tax_rate ?? '0.00',
                    ]);
                }

                $authEngine = app(CreditCardAuthorizationEngine::class);
                $auth = $authEngine->authorizeTransaction($debt, (string) $input['amount'], (string) $input['idempotency_key']);
                if ($auth['uses_overdraft'] && array_key_exists('confirm_overdraft', $input) && !$input['confirm_overdraft']) {
                    throw ValidationException::withMessages([
                        'confirm_overdraft' => ['Esta compra utiliza sobregiro. Revise la vista previa y confirme expresamente el uso del sobregiro antes de guardar.'],
                    ]);
                }

                // Record base debt movement for consumption
                \App\Models\DebtBalanceMovement::query()->create([
                    'user_id' => $user->id,
                    'debt_id' => $debt->id,
                    'account_id' => $account->id,
                    'kind' => 'increase',
                    'delta' => $input['amount'],
                    'description' => sprintf('Consumo tarjeta: %s', $input['description']),
                    'occurred_at' => $occurredAt,
                    'idempotency_key' => (string) $input['idempotency_key'],
                    'event_idempotency_key' => (string) $input['idempotency_key'],
                ]);

                // Record overdraft fee movement if applicable
                if (bccomp($auth['total_fee'], '0.00', 2) > 0) {
                    $rule = $auth['fee_rule'];
                    $ruleId = $rule['id'] ?? 'individual-contract';
                    $feeKey = $authEngine->feeEventIdempotencyKey((string) $input['idempotency_key'], $ruleId);
                    \App\Models\DebtBalanceMovement::query()->create([
                        'user_id' => $user->id,
                        'debt_id' => $debt->id,
                        'account_id' => $account->id,
                        'kind' => 'overdraft_fee',
                        'delta' => $auth['total_fee'],
                        'description' => sprintf('Comisión por sobregiro (%s) [fee-rule:%s]', $input['description'], $ruleId),
                        'occurred_at' => $occurredAt,
                        'idempotency_key' => $feeKey,
                        'event_idempotency_key' => (string) $input['idempotency_key'],
                    ]);
                }

                $debt->update([
                    'outstanding_amount' => bcadd((string) $debt->outstanding_amount, $auth['total_charge'], 2),
                ]);

                if ($debt->commitment_id) {
                    \App\Models\Commitment::query()->where('id', $debt->commitment_id)->update([
                        'current_balance' => $debt->outstanding_amount,
                    ]);
                }
            }

            return ['expense' => $expense, 'account' => $account->refresh(), 'created' => true];
        }, 3);
    }

    public function recordTransfer(User $user, array $input): array
    {
        if ((int) $input['source_account_id'] === (int) $input['destination_account_id']) {
            throw ValidationException::withMessages([
                'destination_account_id' => 'La cuenta de origen y destino deben ser diferentes.',
            ]);
        }

        return DB::transaction(function () use ($user, $input) {
            $existing = Transfer::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $input['idempotency_key'])
                ->first();

            if ($existing) {
                if ((int) $existing->source_account_id !== (int) $input['source_account_id']
                    || (int) $existing->destination_account_id !== (int) $input['destination_account_id']
                    || bccomp((string) $existing->amount, (string) $input['amount'], 2) !== 0) {
                    abort(409, 'La clave de idempotencia ya fue utilizada para una operación diferente.');
                }

                return ['transfer' => $existing, 'created' => false];
            }

            // Deadlock prevention: sort account IDs before locking
            $accountIds = [(int) $input['source_account_id'], (int) $input['destination_account_id']];
            sort($accountIds);

            $lockedAccounts = Account::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->whereIn('id', $accountIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if (!$lockedAccounts->has($input['source_account_id']) || !$lockedAccounts->has($input['destination_account_id'])) {
                throw ValidationException::withMessages([
                    'source_account_id' => 'Una de las cuentas seleccionadas no pertenece al usuario o está archivada.',
                ]);
            }

            $sourceAccount = $lockedAccounts->get($input['source_account_id']);
            $destinationAccount = $lockedAccounts->get($input['destination_account_id']);
            $transferredAt = Carbon::parse($input['transferred_at']);

            $transfer = Transfer::query()->create([
                'user_id' => $user->id,
                'source_account_id' => $sourceAccount->id,
                'destination_account_id' => $destinationAccount->id,
                'amount' => $input['amount'],
                'transferred_at' => $transferredAt,
                'notes' => $input['notes'] ?? null,
                'idempotency_key' => $input['idempotency_key'],
            ]);

            // Debito en cuenta origen
            FinancialTransaction::query()->create([
                'user_id' => $user->id,
                'account_id' => $sourceAccount->id,
                'description' => sprintf('Transferencia enviada a %s', $destinationAccount->name),
                'amount' => bcsub('0.00', (string) $input['amount'], 2),
                'occurred_at' => $transferredAt,
                'kind' => 'transfer_out',
            ]);

            // Crédito en cuenta destino
            FinancialTransaction::query()->create([
                'user_id' => $user->id,
                'account_id' => $destinationAccount->id,
                'description' => sprintf('Transferencia recibida desde %s', $sourceAccount->name),
                'amount' => (string) $input['amount'],
                'occurred_at' => $transferredAt,
                'kind' => 'transfer_in',
            ]);

            return [
                'transfer' => $transfer,
                'source_account' => $sourceAccount->refresh(),
                'destination_account' => $destinationAccount->refresh(),
                'created' => true,
            ];
        }, 3);
    }

    public function recordAdjustment(User $user, Account $account, array $input): array
    {
        return DB::transaction(function () use ($user, $account, $input) {
            $locked = Account::query()
                ->where('user_id', $user->id)
                ->whereKey($account->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentBalance = (string) $locked->transactions()->sum('amount');
            $targetBalance = (string) $input['target_balance'];
            $difference = bcsub($targetBalance, $currentBalance, 2);

            if (bccomp($difference, '0.00', 2) === 0) {
                return ['account' => $locked, 'adjustment_amount' => '0.00'];
            }

            FinancialTransaction::query()->create([
                'user_id' => $user->id,
                'account_id' => $locked->id,
                'description' => sprintf('Ajuste de conciliación: %s', $input['reason']),
                'amount' => $difference,
                'occurred_at' => now(),
                'kind' => 'adjustment',
            ]);

            return ['account' => $locked->refresh(), 'adjustment_amount' => $difference];
        }, 3);
    }

    public function updateExpense(User $user, int $expenseId, array $input): array
    {
        return DB::transaction(function () use ($user, $expenseId, $input) {
            $expense = Expense::query()->where('user_id', $user->id)->whereKey($expenseId)->firstOrFail();
            $newAccount = Account::query()->where('user_id', $user->id)->where('is_active', true)->whereKey($input['account_id'])->lockForUpdate()->firstOrFail();
            $oldAccount = Account::query()->where('user_id', $user->id)->whereKey($expense->account_id)->firstOrFail();
            $occurredAt = Carbon::parse($input['occurred_at']);

            if ($newAccount->type !== 'credit_card') {
                $currentBalance = (string) ($newAccount->transactions()->sum('amount') ?: '0.00');
                $available = ($newAccount->id === $oldAccount->id)
                    ? bcadd($currentBalance, (string) $expense->amount, 2)
                    : $currentBalance;

                if (bccomp($available, (string) $input['amount'], 2) < 0) {
                    $formatted = number_format((float) $available, 2, '.', ',');
                    throw ValidationException::withMessages([
                        'account_id' => [sprintf('La cuenta "%s" no tiene fondos suficientes para este monto. Balance disponible: RD$ %s', $newAccount->name, $formatted)],
                    ]);
                }
            }

            $tx = FinancialTransaction::query()
                ->where('user_id', $user->id)
                ->where('account_id', $expense->account_id)
                ->where('kind', 'expense')
                ->where('occurred_at', $expense->occurred_at)
                ->first();

            if ($tx) {
                $tx->update([
                    'account_id' => $newAccount->id,
                    'description' => sprintf('%s: %s', $input['category'], $input['description']),
                    'amount' => bcsub('0.00', (string) $input['amount'], 2),
                    'occurred_at' => $occurredAt,
                ]);
            }

            if ($oldAccount->type === 'credit_card') {
                $debt = \App\Models\Debt::query()->where('user_id', $user->id)->where('account_id', $oldAccount->id)->first();
                if ($debt) {
                    $debt->update([
                        'outstanding_amount' => bcsub((string) $debt->outstanding_amount, (string) $expense->amount, 2),
                    ]);
                }
            }
            if ($newAccount->type === 'credit_card') {
                $debt = \App\Models\Debt::query()->where('user_id', $user->id)->where('account_id', $newAccount->id)->first();
                if ($debt) {
                    $authEngine = app(CreditCardAuthorizationEngine::class);
                    $auth = $authEngine->authorizeTransaction($debt, (string) $input['amount']);

                    $debt->update([
                        'outstanding_amount' => bcadd((string) $debt->outstanding_amount, $auth['total_charge'], 2),
                    ]);
                }
            }

            $expense->update([
                'account_id' => $newAccount->id,
                'category' => $input['category'],
                'amount' => $input['amount'],
                'occurred_at' => $occurredAt,
                'description' => $input['description'],
                'reference_number' => $input['reference_number'] ?? null,
            ]);

            return ['expense' => $expense->refresh(), 'account' => $newAccount->refresh()];
        }, 3);
    }

    public function deleteExpense(User $user, int $expenseId): void
    {
        DB::transaction(function () use ($user, $expenseId) {
            $expense = Expense::query()->where('user_id', $user->id)->whereKey($expenseId)->firstOrFail();
            $account = Account::query()->where('user_id', $user->id)->whereKey($expense->account_id)->firstOrFail();

            FinancialTransaction::query()
                ->where('user_id', $user->id)
                ->where('account_id', $expense->account_id)
                ->where('kind', 'expense')
                ->where('occurred_at', $expense->occurred_at)
                ->delete();

            if ($account->type === 'credit_card') {
                $debt = \App\Models\Debt::query()->where('user_id', $user->id)->where('account_id', $account->id)->first();
                if ($debt) {
                    $feeMovements = \App\Models\DebtBalanceMovement::query()
                        ->where('debt_id', $debt->id)
                        ->where('kind', 'overdraft_fee')
                        ->where('event_idempotency_key', $expense->idempotency_key)
                        ->get();
                    $feeTotal = '0.00';
                    foreach ($feeMovements as $feeMovement) {
                        if (\App\Models\DebtBalanceMovement::query()
                            ->where('reversal_of_id', $feeMovement->id)
                            ->exists()) {
                            continue;
                        }
                        $feeTotal = bcadd($feeTotal, (string) $feeMovement->delta, 2);
                        \App\Models\DebtBalanceMovement::query()->create([
                            'user_id' => $user->id,
                            'debt_id' => $debt->id,
                            'reversal_of_id' => $feeMovement->id,
                            'account_id' => $account->id,
                            'kind' => 'reversal',
                            'delta' => bcsub('0.00', (string) $feeMovement->delta, 2),
                            'description' => 'Reversión trazable de comisión por sobregiro',
                            'occurred_at' => now(),
                            'idempotency_key' => $this->uuidFromSeed('fee-reversal:' . $expense->idempotency_key . ':' . $feeMovement->id),
                            'event_idempotency_key' => (string) $expense->idempotency_key,
                        ]);
                    }
                    $debt->update([
                        'outstanding_amount' => bcsub(
                            bcsub((string) $debt->outstanding_amount, (string) $expense->amount, 2),
                            $feeTotal,
                            2,
                        ),
                    ]);
                }
            }

            $expense->delete();
        }, 3);
    }

    private function uuidFromSeed(string $seed): string
    {
        $hex = hash('sha256', $seed);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
