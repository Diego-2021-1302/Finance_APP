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
                    || bccomp((string) $existing->delta, bcsub('0.00', (string) $input['amount'], 2), 2) !== 0
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

            if (bccomp((string) $input['amount'], (string) $record->outstanding_amount, 2) === 1) {
                throw ValidationException::withMessages(['amount' => 'El pago no puede exceder el saldo pendiente.']);
            }

            $paidAt = Carbon::parse($input['paid_at']);
            $movement = DebtBalanceMovement::query()->create([
                'user_id' => $user->id,
                'debt_id' => $record->id,
                'account_id' => $account->id,
                'kind' => 'payment',
                'delta' => bcsub('0.00', (string) $input['amount'], 2),
                'description' => 'Pago de deuda',
                'occurred_at' => $paidAt,
                'idempotency_key' => $input['idempotency_key'],
            ]);
            $record->update(['outstanding_amount' => bcsub((string) $record->outstanding_amount, (string) $input['amount'], 2)]);
            FinancialTransaction::query()->create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'description' => 'Abono a tarjeta / deuda: '.$record->name,
                'amount' => bcsub('0.00', (string) $input['amount'], 2),
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
                    'amount' => (string) $input['amount'],
                    'occurred_at' => $paidAt,
                    'kind' => 'transfer_in',
                ]);
            }

            return ['debt' => $record->refresh(), 'movement' => $movement, 'created' => true];
        }, 3);
    }
}
