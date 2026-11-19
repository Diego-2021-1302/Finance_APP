<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commitment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->date('statement_date');
            $table->decimal('closing_balance', 15, 2);
            $table->date('due_date');
            $table->decimal('minimum_payment', 15, 2)->nullable();
            $table->boolean('minimum_payment_confirmed')->default(false);
            $table->dateTime('minimum_payment_confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['commitment_id', 'statement_date'], 'card_statements_commitment_date_uq');
            $table->index(['user_id', 'statement_date'], 'card_statements_user_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_statements');
    }
};
