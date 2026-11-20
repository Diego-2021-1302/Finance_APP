<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['commitments', 'debts', 'accounts'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'special_rules_documented')) {
                    $table->boolean('special_rules_documented')->default(false);
                }
                if (!Schema::hasColumn($tableName, 'special_rules_source')) {
                    $table->string('special_rules_source', 2048)->nullable();
                }
                if (!Schema::hasColumn($tableName, 'special_rules_reference')) {
                    $table->string('special_rules_reference', 255)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['commitments', 'debts', 'accounts'] as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn([
                        'special_rules_documented',
                        'special_rules_source',
                        'special_rules_reference',
                    ]);
                });
            }
        }
    }
};
