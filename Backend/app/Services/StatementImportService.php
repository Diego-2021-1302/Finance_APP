<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FinancialTransaction;
use App\Models\ImportedTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StatementImportService
{
    public function importCsv(User $user, Account $account, string $csvContent): array
    {
        $lines = array_filter(explode("\n", str_replace("\r", "", $csvContent)));
        $imported = [];

        return DB::transaction(function () use ($user, $account, $lines, &$imported) {
            foreach ($lines as $index => $line) {
                if ($index === 0 && stripos($line, 'date') !== false) {
                    continue;
                }

                $cols = str_getcsv($line);
                if (count($cols) < 3) {
                    continue;
                }

                $date = trim($cols[0]);
                $description = trim($cols[1]);
                $amount = trim($cols[2]);

                if (!is_numeric($amount)) {
                    continue;
                }

                $occurredAt = Carbon::parse($date)->toDateString();
                $exists = ImportedTransaction::query()
                    ->where('user_id', $user->id)
                    ->where('account_id', $account->id)
                    ->where('occurred_at', $occurredAt)
                    ->where('amount', $amount)
                    ->where('description', $description)
                    ->exists();

                $status = $exists ? 'duplicate' : 'pending';

                $tx = ImportedTransaction::query()->create([
                    'user_id' => $user->id,
                    'account_id' => $account->id,
                    'occurred_at' => $occurredAt,
                    'description' => $description,
                    'amount' => $amount,
                    'status' => $status,
                ]);

                $imported[] = $tx;
            }

            return $imported;
        });
    }

    public function confirm(ImportedTransaction $transaction): ImportedTransaction
    {
        return DB::transaction(function () use ($transaction) {
            $locked = ImportedTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'confirmed' && $locked->financial_transaction_id) {
                return $locked;
            }

            if ($locked->financial_transaction_id === null) {
                $kind = bccomp((string) $locked->amount, '0.00', 2) >= 0 ? 'income_receipt' : 'expense';
                $financialTx = FinancialTransaction::query()->create([
                    'user_id' => $locked->user_id,
                    'account_id' => $locked->account_id,
                    'description' => 'Reconciled: ' . $locked->description,
                    'amount' => $locked->amount,
                    'occurred_at' => $locked->occurred_at,
                    'kind' => $kind,
                ]);

                $locked->update([
                    'status' => 'confirmed',
                    'financial_transaction_id' => $financialTx->id,
                ]);
            } else {
                $locked->update(['status' => 'confirmed']);
            }

            return $locked->refresh();
        });
    }

    public function revert(ImportedTransaction $transaction): ImportedTransaction
    {
        return DB::transaction(function () use ($transaction) {
            $locked = ImportedTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($locked->financial_transaction_id) {
                FinancialTransaction::query()->whereKey($locked->financial_transaction_id)->delete();
            }
            $locked->update([
                'status' => 'pending',
                'financial_transaction_id' => null,
            ]);

            return $locked->refresh();
        });
    }
}
