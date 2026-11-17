<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commitments', function (Blueprint $table) {
            $table->unsignedTinyInteger('statement_day')->nullable()->after('interest_rate');
            $table->unsignedTinyInteger('payment_grace_days')->nullable()->default(21)->after('statement_day');
            $table->decimal('statement_balance', 15, 2)->nullable()->after('payment_grace_days');
        });

        Schema::table('debts', function (Blueprint $table) {
            $table->unsignedTinyInteger('statement_day')->nullable()->after('minimum_payment');
            $table->unsignedTinyInteger('payment_grace_days')->nullable()->default(21)->after('statement_day');
            $table->decimal('statement_balance', 15, 2)->nullable()->after('payment_grace_days');
        });
    }

    public function down(): void
    {
        Schema::table('commitments', function (Blueprint $table) {
            $table->dropColumn(['statement_day', 'payment_grace_days', 'statement_balance']);
        });

        Schema::table('debts', function (Blueprint $table) {
            $table->dropColumn(['statement_day', 'payment_grace_days', 'statement_balance']);
        });
    }
};
