<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_debt_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7);
            $table->decimal('total_debt', 15, 2);
            $table->decimal('card_debt', 15, 2)->default(0);
            $table->decimal('loan_debt', 15, 2)->default(0);
            $table->decimal('other_debt', 15, 2)->default(0);
            $table->date('captured_at');
            $table->string('source', 32)->default('month_end');
            $table->timestamps();
            $table->unique(['user_id', 'month']);
            $table->index(['user_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_debt_history');
    }
};
