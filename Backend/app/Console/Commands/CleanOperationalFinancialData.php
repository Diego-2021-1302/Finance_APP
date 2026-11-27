<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanOperationalFinancialData extends Command
{
    protected $signature = 'finance:clean-operational-data {--force : Execute the cleanup instead of running in preview mode}';

    protected $description = 'Remove income, expense and payment operational data while preserving users, accounts, savings and preferences';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $counts = $this->counts();
        $preserved = [
            'users' => DB::table('users')->count(),
            'accounts' => DB::table('accounts')->count(),
            'reserves' => DB::table('reserves')->count(),
            'budgets' => DB::table('budgets')->count(),
            'piggy_bank_deposits' => DB::table('piggy_bank_deposits')->count(),
            'account_transfers' => DB::table('account_transfers')->count(),
        ];

        $this->table(['Scope', 'Records'], array_merge(
            array_map(fn ($name, $count) => [$name, $count], array_keys($counts), array_values($counts)),
            array_map(fn ($name, $count) => ['PRESERVE ' . $name, $count], array_keys($preserved), array_values($preserved)),
        ));

        if (!$force) {
            $this->warn('Preview only. Re-run with --force to execute the cleanup in a database transaction.');
            return self::SUCCESS;
        }

        DB::transaction(function () {
            DB::table('notification_deliveries')->delete();
            DB::table('imported_transactions')->delete();
            DB::table('monthly_debt_history')->delete();
            DB::table('debt_balance_movements')->delete();
            DB::table('credit_card_statements')->delete();

            DB::table('transactions')->whereIn('kind', [
                'income_receipt',
                'commitment_payment',
                'debt_payment',
                'expense',
                'increase',
                'overdraft_fee',
                'reversal',
            ])->delete();

            DB::table('payments')->delete();
            DB::table('expenses')->delete();
            DB::table('incomes')->delete();
            DB::table('commitment_occurrences')->delete();
            DB::table('commitments')->delete();
            DB::table('debts')->delete();

            DB::table('accounts')
                ->whereNull('debt_id')
                ->whereNull('commitment_id')
                ->update(['updated_at' => now()]);
        }, 3);

        $this->info('Operational financial data cleaned successfully.');
        $this->table(['Preserved scope', 'Records'], array_map(
            fn ($name, $count) => [$name, $count],
            array_keys($preserved),
            array_values($preserved),
        ));

        return self::SUCCESS;
    }

    private function counts(): array
    {
        return [
            'incomes' => DB::table('incomes')->count(),
            'expenses' => DB::table('expenses')->count(),
            'payments' => DB::table('payments')->count(),
            'financial_transactions' => DB::table('transactions')->count(),
            'commitments' => DB::table('commitments')->count(),
            'commitment_occurrences' => DB::table('commitment_occurrences')->count(),
            'debts' => DB::table('debts')->count(),
            'credit_card_statements' => DB::table('credit_card_statements')->count(),
            'debt_balance_movements' => DB::table('debt_balance_movements')->count(),
            'monthly_debt_history' => DB::table('monthly_debt_history')->count(),
            'imported_transactions' => DB::table('imported_transactions')->count(),
            'notification_deliveries' => DB::table('notification_deliveries')->count(),
        ];
    }
}
