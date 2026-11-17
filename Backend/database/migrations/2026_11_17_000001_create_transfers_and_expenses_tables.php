<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('category', 64);
            $table->decimal('amount', 15, 2);
            $table->dateTime('occurred_at');
            $table->string('description', 500);
            $table->string('reference_number', 80)->nullable();
            $table->uuid('idempotency_key');
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key'], 'expenses_user_idempotency_uq');
            $table->index(['user_id', 'occurred_at']);
            $table->index(['account_id', 'occurred_at']);
        });

        Schema::create('account_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('destination_account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->dateTime('transferred_at');
            $table->string('notes', 500)->nullable();
            $table->uuid('idempotency_key');
            $table->dateTime('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->uuid('reversal_key')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key'], 'transfers_user_idempotency_uq');
            $table->unique(['user_id', 'reversal_key'], 'transfers_user_reversal_key_uq');
            $table->index(['user_id', 'transferred_at']);
            $table->index(['source_account_id', 'transferred_at']);
            $table->index(['destination_account_id', 'transferred_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE transactions DROP CHECK transactions_kind_chk');
            DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_kind_chk CHECK (kind IN ('income_receipt', 'commitment_payment', 'debt_payment', 'account_opening', 'expense', 'transfer_out', 'transfer_in', 'adjustment', 'reversal'))");
            DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_positive_chk CHECK (amount > 0)');
            DB::statement('ALTER TABLE account_transfers ADD CONSTRAINT transfers_amount_positive_chk CHECK (amount > 0)');
            DB::statement('ALTER TABLE account_transfers ADD CONSTRAINT transfers_different_accounts_chk CHECK (source_account_id <> destination_account_id)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE transactions DROP CHECK transactions_kind_chk');
            DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_kind_chk CHECK (kind IN ('income_receipt', 'commitment_payment', 'debt_payment', 'account_opening', 'reversal'))");
        }

        Schema::dropIfExists('account_transfers');
        Schema::dropIfExists('expenses');
    }
};
