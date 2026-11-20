<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commitments', function (Blueprint $table) {
            $table->decimal('credit_limit', 15, 2)->nullable()->after('statement_balance');
        });

        Schema::table('debts', function (Blueprint $table) {
            $table->decimal('credit_limit', 15, 2)->nullable()->after('statement_balance');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('credit_limit', 15, 2)->nullable()->after('last_four');
        });
    }

    public function down(): void
    {
        Schema::table('commitments', function (Blueprint $table) {
            $table->dropColumn(['credit_limit']);
        });

        Schema::table('debts', function (Blueprint $table) {
            $table->dropColumn(['credit_limit']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['credit_limit']);
        });
    }
};
