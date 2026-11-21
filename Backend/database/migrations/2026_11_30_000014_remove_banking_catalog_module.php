<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['commitments', 'debts', 'accounts'] as $tableName) {
            if (!Schema::hasTable($tableName)) continue;

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'financial_product_variant_id')) {
                    $table->dropForeign([$tableName === 'accounts' ? 'financial_product_variant_id' : 'financial_product_variant_id']);
                    $table->dropColumn('financial_product_variant_id');
                }
                if (Schema::hasColumn($tableName, 'financial_institution_id')) {
                    $table->dropForeign(['financial_institution_id']);
                    $table->dropColumn('financial_institution_id');
                }
            });
        }

        foreach ([
            'credit_card_fee_rules',
            'financial_tariff_sync_logs',
            'financial_tariff_records',
            'financial_product_variants',
            'financial_products',
            'financial_institutions',
        ] as $tableName) {
            Schema::dropIfExists($tableName);
        }

        if (Schema::hasTable('financial_product_variants')) {
            Schema::table('financial_product_variants', function (Blueprint $table) {
                foreach (['benefits', 'payment_methods', 'official_source'] as $column) {
                    if (Schema::hasColumn('financial_product_variants', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // The removed catalog is intentionally not recreated by down().
    }
};
