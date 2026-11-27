<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('accounts')
            ->where('type', 'credit_card')
            ->where('is_active', false)
            ->where(function ($query) {
                $query->whereIn('id', DB::table('commitments')
                    ->where('type', 'credit_card')
                    ->where('is_active', true)
                    ->whereNotNull('account_id')
                    ->select('account_id'))
                    ->orWhereIn('commitment_id', DB::table('commitments')
                        ->where('type', 'credit_card')
                        ->where('is_active', true)
                        ->select('id'))
                    ->orWhereIn('id', DB::table('debts')
                        ->where('kind', 'credit_card')
                        ->where('outstanding_amount', '>', 0)
                        ->whereNotNull('account_id')
                        ->select('account_id'))
                    ->orWhereIn('debt_id', DB::table('debts')
                        ->where('kind', 'credit_card')
                        ->where('outstanding_amount', '>', 0)
                        ->select('id'));
            })
            ->update(['is_active' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
    }
};