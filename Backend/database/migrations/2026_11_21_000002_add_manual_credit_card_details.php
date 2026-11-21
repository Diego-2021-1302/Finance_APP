<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['commitments', 'accounts', 'debts'] as $tableName) {
            if (!Schema::hasTable($tableName)) continue;

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'card_product_name')) {
                    $table->string('card_product_name', 160)->nullable();
                }
                if (!Schema::hasColumn($tableName, 'card_variant')) {
                    $table->string('card_variant', 160)->nullable();
                }
                if (!Schema::hasColumn($tableName, 'card_network')) {
                    $table->string('card_network', 32)->nullable();
                }
                if (!Schema::hasColumn($tableName, 'card_currency')) {
                    $table->char('card_currency', 3)->nullable();
                }
                if (!Schema::hasColumn($tableName, 'issued_at')) {
                    $table->date('issued_at')->nullable();
                }
                if (!Schema::hasColumn($tableName, 'annual_fee')) {
                    $table->decimal('annual_fee', 15, 2)->nullable();
                }
                if (!Schema::hasColumn($tableName, 'replacement_fee')) {
                    $table->decimal('replacement_fee', 15, 2)->nullable();
                }
                if (!Schema::hasColumn($tableName, 'cash_advance_fee')) {
                    $table->decimal('cash_advance_fee', 15, 2)->nullable();
                }
                if (!Schema::hasColumn($tableName, 'late_fee')) {
                    $table->decimal('late_fee', 15, 2)->nullable();
                }
                if (!Schema::hasColumn($tableName, 'card_benefits')) {
                    $table->json('card_benefits')->nullable();
                }
                if (!Schema::hasColumn($tableName, 'card_payment_methods')) {
                    $table->json('card_payment_methods')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['commitments', 'accounts', 'debts'] as $tableName) {
            if (!Schema::hasTable($tableName)) continue;

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $columns = [];
                foreach (['card_product_name', 'card_variant', 'card_network', 'card_currency', 'issued_at', 'annual_fee', 'replacement_fee', 'cash_advance_fee', 'late_fee', 'card_benefits', 'card_payment_methods'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) $columns[] = $column;
                }
                if ($columns !== []) $table->dropColumn($columns);
            });
        }
    }
};
