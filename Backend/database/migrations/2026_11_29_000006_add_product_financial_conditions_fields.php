<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $addColumns = function (Blueprint $table) {
            $table->decimal('cash_advance_interest_rate', 6, 3)->nullable()->after('overdraft_fee_tax_rate');
            $table->decimal('moratorium_interest_rate', 6, 3)->nullable()->after('cash_advance_interest_rate');
            $table->decimal('membership_fee_amount', 15, 2)->default(0.00)->after('moratorium_interest_rate');
            $table->decimal('insurance_fee_amount', 15, 2)->default(0.00)->after('membership_fee_amount');
            $table->decimal('closing_fee_percentage', 5, 2)->default(0.00)->after('insurance_fee_amount');
            $table->decimal('late_fee_percentage', 5, 2)->default(0.00)->after('closing_fee_percentage');
            $table->decimal('late_fee_fixed', 15, 2)->default(0.00)->after('late_fee_percentage');
            $table->string('amortization_type', 32)->default('french')->after('late_fee_fixed');
            $table->unsignedSmallInteger('term_months')->nullable()->after('amortization_type');
            $table->decimal('promo_interest_rate', 6, 3)->nullable()->after('term_months');
            $table->date('promo_until_date')->nullable()->after('promo_interest_rate');
        };

        Schema::table('commitments', $addColumns);
        Schema::table('debts', $addColumns);
        Schema::table('accounts', $addColumns);
        Schema::table('financial_product_variants', $addColumns);
    }

    public function down(): void
    {
        $dropColumns = function (Blueprint $table) {
            $table->dropColumn([
                'cash_advance_interest_rate', 'moratorium_interest_rate', 'membership_fee_amount',
                'insurance_fee_amount', 'closing_fee_percentage', 'late_fee_percentage',
                'late_fee_fixed', 'amortization_type', 'term_months',
                'promo_interest_rate', 'promo_until_date',
            ]);
        };

        Schema::table('commitments', $dropColumns);
        Schema::table('debts', $dropColumns);
        Schema::table('accounts', $dropColumns);
        Schema::table('financial_product_variants', $dropColumns);
    }
};
