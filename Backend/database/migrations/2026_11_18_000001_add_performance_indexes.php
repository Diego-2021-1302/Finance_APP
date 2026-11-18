<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['user_id', 'occurred_at'], 'idx_tx_user_date');
            $table->index(['user_id', 'account_id', 'occurred_at'], 'idx_tx_user_acc_date');
        });

        Schema::table('commitments', function (Blueprint $table) {
            $table->index(['user_id', 'is_active', 'next_due_date'], 'idx_comm_user_active_due');
        });

        Schema::table('commitment_occurrences', function (Blueprint $table) {
            $table->index(['user_id', 'status', 'due_date'], 'idx_occ_user_status_due');
        });

        Schema::table('incomes', function (Blueprint $table) {
            $table->index(['user_id', 'expected_date'], 'idx_inc_user_date');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_tx_user_date');
            $table->dropIndex('idx_tx_user_acc_date');
        });

        Schema::table('commitments', function (Blueprint $table) {
            $table->dropIndex('idx_comm_user_active_due');
        });

        Schema::table('commitment_occurrences', function (Blueprint $table) {
            $table->dropIndex('idx_occ_user_status_due');
        });

        Schema::table('incomes', function (Blueprint $table) {
            $table->dropIndex('idx_inc_user_date');
        });
    }
};
