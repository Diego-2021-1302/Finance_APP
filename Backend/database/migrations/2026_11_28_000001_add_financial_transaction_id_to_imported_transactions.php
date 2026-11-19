<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imported_transactions', function (Blueprint $table) {
            $table->foreignId('financial_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('imported_transactions', function (Blueprint $table) {
            $table->dropForeign(['financial_transaction_id']);
            $table->dropColumn('financial_transaction_id');
        });
    }
};
