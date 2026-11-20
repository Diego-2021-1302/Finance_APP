<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['debts', 'commitments', 'accounts'] as $tableName) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'limit_status')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->string('limit_status', 32)->default('unknown')->after('approved_credit_limit');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['debts', 'commitments', 'accounts'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'limit_status')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn(['limit_status']);
                });
            }
        }
    }
};
