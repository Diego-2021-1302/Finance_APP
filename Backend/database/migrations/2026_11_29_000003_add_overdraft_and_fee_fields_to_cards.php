<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commitments', function (Blueprint $table) {
            $table->decimal('approved_credit_limit', 15, 2)->nullable()->after('credit_limit');
            $table->decimal('authorized_overdraft_limit', 15, 2)->default(0.00)->after('approved_credit_limit');
            $table->string('overdraft_fee_type', 32)->default('none')->after('authorized_overdraft_limit');
            $table->decimal('overdraft_fee_amount', 15, 2)->default(0.00)->after('overdraft_fee_type');
            $table->decimal('overdraft_fee_percentage', 5, 2)->default(0.00)->after('overdraft_fee_amount');
            $table->decimal('overdraft_fee_tax_rate', 5, 2)->default(0.00)->after('overdraft_fee_percentage');
        });

        Schema::table('debts', function (Blueprint $table) {
            $table->decimal('approved_credit_limit', 15, 2)->nullable()->after('credit_limit');
            $table->decimal('authorized_overdraft_limit', 15, 2)->default(0.00)->after('approved_credit_limit');
            $table->string('overdraft_fee_type', 32)->default('none')->after('authorized_overdraft_limit');
            $table->decimal('overdraft_fee_amount', 15, 2)->default(0.00)->after('overdraft_fee_type');
            $table->decimal('overdraft_fee_percentage', 5, 2)->default(0.00)->after('overdraft_fee_amount');
            $table->decimal('overdraft_fee_tax_rate', 5, 2)->default(0.00)->after('overdraft_fee_percentage');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('approved_credit_limit', 15, 2)->nullable()->after('credit_limit');
            $table->decimal('authorized_overdraft_limit', 15, 2)->default(0.00)->after('approved_credit_limit');
            $table->string('overdraft_fee_type', 32)->default('none')->after('authorized_overdraft_limit');
            $table->decimal('overdraft_fee_amount', 15, 2)->default(0.00)->after('overdraft_fee_type');
            $table->decimal('overdraft_fee_percentage', 5, 2)->default(0.00)->after('overdraft_fee_amount');
            $table->decimal('overdraft_fee_tax_rate', 5, 2)->default(0.00)->after('overdraft_fee_percentage');
        });

        // Backfill approved_credit_limit from credit_limit if set
        DB::table('commitments')->whereNotNull('credit_limit')->update(['approved_credit_limit' => DB::raw('credit_limit')]);
        DB::table('debts')->whereNotNull('credit_limit')->update(['approved_credit_limit' => DB::raw('credit_limit')]);
        DB::table('accounts')->whereNotNull('credit_limit')->update(['approved_credit_limit' => DB::raw('credit_limit')]);
    }

    public function down(): void
    {
        Schema::table('commitments', function (Blueprint $table) {
            $table->dropColumn([
                'approved_credit_limit', 'authorized_overdraft_limit', 'overdraft_fee_type',
                'overdraft_fee_amount', 'overdraft_fee_percentage', 'overdraft_fee_tax_rate',
            ]);
        });

        Schema::table('debts', function (Blueprint $table) {
            $table->dropColumn([
                'approved_credit_limit', 'authorized_overdraft_limit', 'overdraft_fee_type',
                'overdraft_fee_amount', 'overdraft_fee_percentage', 'overdraft_fee_tax_rate',
            ]);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn([
                'approved_credit_limit', 'authorized_overdraft_limit', 'overdraft_fee_type',
                'overdraft_fee_amount', 'overdraft_fee_percentage', 'overdraft_fee_tax_rate',
            ]);
        });
    }
};
