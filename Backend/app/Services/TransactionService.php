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

            // If account is a credit card, sync with credit card debt balance
            if ($account->type === 'credit_card') {
                $debt = \App\Models\Debt::query()
                    ->where('user_id', $user->id)
                    ->where(function ($query) use ($account) {
                        $query->where('account_id', $account->id)
                            ->orWhere('name', $account->name);
                    })
                    ->lockForUpdate()
                    ->first();

                if (!$debt) {
                    $debt = \App\Models\Debt::query()->create([
                        'user_id' => $user->id,
                        'account_id' => $account->id,
                        'name' => $account->name,
                        'kind' => 'credit_card',
                        'outstanding_amount' => $input['amount'],
                        'minimum_payment' => '0.00',
                        'minimum_payment_confirmed' => false,
                    ]);
                } else {
                    $debt->update([
                        'outstanding_amount' => bcadd((string) $debt->outstanding_amount, (string) $input['amount'], 2),
                    ]);
                }

                \App\Models\DebtBalanceMovement::query()->create([
                    'user_id' => $user->id,
                    'debt_id' => $debt->id,
                    'account_id' => $account->id,
                    'kind' => 'increase',
                    'delta' => $input['amount'],
                    'description' => sprintf('Consumo tarjeta: %s', $input['description']),
                    'occurred_at' => $occurredAt,
                    'idempotency_key' => Str::uuid()->toString(),
                ]);
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
}
