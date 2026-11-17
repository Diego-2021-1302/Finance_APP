<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\FinancialTransaction;
use App\Models\Income;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordIncomeReceipt
{
    public function execute(User $user, Income $income, array $input): Payment
    {
        return DB::transaction(function () use ($user, $income, $input) {
            $existing = Payment::query()->where('user_id', $user->id)->where('idempotency_key', $input['idempotency_key'])->first();
            if ($existing) {
                if ((int) $existing->income_id !== (int) $income->id || (int) $existing->account_id !== (int) $input['account_id'] || bccomp((string) $existing->amount, (string) $input['amount'], 2) !== 0) {
                    abort(409, 'La clave de idempotencia ya fue utilizada para una operación diferente.');
                }
                return $existing;
            }

            $account = Account::query()->where('user_id', $user->id)->whereKey($input['account_id'])->lockForUpdate()->firstOrFail();
            $received = Payment::query()->where('income_id', $income->id)->whereNull('reversed_at')->sum('amount');
            if (bccomp(bcadd((string) $received, (string) $input['amount'], 2), (string) $income->amount, 2) === 1) {
                throw ValidationException::withMessages(['amount' => 'El recibo excede el ingreso esperado restante.']);
            }

            $payment = Payment::query()->create([
                'user_id' => $user->id, 'income_id' => $income->id, 'account_id' => $account->id,
                'amount' => $input['amount'], 'paid_at' => $input['received_at'], 'idempotency_key' => $input['idempotency_key'],
            ]);
            FinancialTransaction::query()->create([
                'user_id' => $user->id, 'account_id' => $account->id, 'payment_id' => $payment->id,
                'description' => $income->source, 'amount' => $input['amount'],
                'occurred_at' => $input['received_at'], 'kind' => 'income_receipt',
            ]);

            return $payment;
        }, 3);
    }
}
