<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debt_balance_movements', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('debt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->after('payment_id')->constrained('debt_balance_movements')->cascadeOnDelete();
            $table->unique('payment_id', 'debt_movements_payment_uq');
            $table->unique('reversal_of_id', 'debt_movements_reversal_uq');
        });
    }

    public function down(): void
    {
        Schema::table('debt_balance_movements', function (Blueprint $table) {
            $table->dropUnique('debt_movements_payment_uq');
            $table->dropUnique('debt_movements_reversal_uq');
            $table->dropConstrainedForeignId('payment_id');
            $table->dropConstrainedForeignId('reversal_of_id');
        });
    }
};
