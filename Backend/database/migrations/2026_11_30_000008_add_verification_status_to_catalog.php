<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['financial_products', 'financial_product_variants'] as $tableName) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'verification_status')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->string('verification_status', 32)->default('verified')->after('verified');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['financial_products', 'financial_product_variants'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'verification_status')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn(['verification_status']);
                });
            }
        }
    }
};
