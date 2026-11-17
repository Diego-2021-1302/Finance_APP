<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('institution', 160)->nullable();
            $table->string('type', 32);
            $table->char('currency', 3)->default('DOP');
            $table->char('last_four', 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 160);
            $table->decimal('amount', 15, 2);
            $table->date('expected_date');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'expected_date']);
        });

        Schema::create('commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->decimal('amount', 15, 2);
            $table->string('frequency', 32);
            $table->date('next_due_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'is_active', 'next_due_date'], 'commitments_user_active_due_idx');
        });

        Schema::create('commitment_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commitment_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount_due', 15, 2);
            $table->date('due_date');
            $table->string('status', 24)->default('scheduled');
            $table->unsignedInteger('schedule_version')->default(1);
            $table->timestamps();
            $table->unique(['commitment_id', 'due_date', 'schedule_version'], 'occurrences_commitment_date_version_uq');
            $table->index(['user_id', 'due_date', 'status'], 'occurrences_user_due_status_idx');
        });

        Schema::create('debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('kind', 32);
            $table->decimal('outstanding_amount', 15, 2);
            $table->decimal('minimum_payment', 15, 2);
            $table->date('due_date')->nullable();
            $table->char('last_four', 4)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'due_date']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('income_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('commitment_occurrence_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->dateTime('paid_at');
            $table->uuid('idempotency_key');
            $table->dateTime('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->uuid('reversal_key')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key'], 'payments_user_idempotency_uq');
            $table->unique(['user_id', 'reversal_key'], 'payments_user_reversal_key_uq');
            $table->index(['user_id', 'paid_at']);
            $table->index(['income_id', 'reversed_at']);
            $table->index(['commitment_occurrence_id', 'reversed_at'], 'payments_occurrence_reversed_idx');
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description', 500);
            $table->decimal('amount', 15, 2);
            $table->dateTime('occurred_at');
            $table->string('kind', 32);
            $table->foreignId('reversal_of_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'occurred_at']);
            $table->index(['account_id', 'occurred_at']);
            $table->index('payment_id');
        });

        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('currency', 3)->default('DOP');
            $table->string('timezone', 64)->default('America/Santo_Domingo');
            $table->unsignedTinyInteger('reminders')->default(3);
            $table->string('week_starts', 8)->default('Monday');
            $table->string('appearance', 8)->default('system');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_currency_chk CHECK (currency IN ('DOP', 'USD'))");
            DB::statement("ALTER TABLE incomes ADD CONSTRAINT incomes_amount_positive_chk CHECK (amount > 0)");
            DB::statement("ALTER TABLE commitments ADD CONSTRAINT commitments_amount_positive_chk CHECK (amount > 0)");
            DB::statement("ALTER TABLE commitment_occurrences ADD CONSTRAINT occurrences_amount_positive_chk CHECK (amount_due > 0)");
            DB::statement("ALTER TABLE commitment_occurrences ADD CONSTRAINT occurrences_status_chk CHECK (status IN ('scheduled', 'paid'))");
            DB::statement("ALTER TABLE debts ADD CONSTRAINT debts_amounts_nonnegative_chk CHECK (outstanding_amount >= 0 AND minimum_payment >= 0)");
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_amount_positive_chk CHECK (amount > 0)");
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_parent_xor_chk CHECK ((income_id IS NOT NULL AND commitment_occurrence_id IS NULL) OR (income_id IS NULL AND commitment_occurrence_id IS NOT NULL))");
            DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_kind_chk CHECK (kind IN ('income_receipt', 'commitment_payment', 'reversal'))");
            DB::statement("ALTER TABLE user_preferences ADD CONSTRAINT preferences_currency_chk CHECK (currency IN ('DOP', 'USD'))");
            DB::statement("ALTER TABLE user_preferences ADD CONSTRAINT preferences_reminders_chk CHECK (reminders BETWEEN 1 AND 14)");
            DB::statement("ALTER TABLE user_preferences ADD CONSTRAINT preferences_week_start_chk CHECK (week_starts IN ('Monday', 'Sunday'))");
            DB::statement("ALTER TABLE user_preferences ADD CONSTRAINT preferences_appearance_chk CHECK (appearance IN ('light', 'dark', 'system'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('debts');
        Schema::dropIfExists('commitment_occurrences');
        Schema::dropIfExists('commitments');
        Schema::dropIfExists('incomes');
        Schema::dropIfExists('accounts');
    }
};
