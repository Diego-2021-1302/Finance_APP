<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_balance_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 24);
            $table->decimal('delta', 15, 2);
            $table->string('description', 255)->nullable();
            $table->dateTime('occurred_at');
            $table->uuid('idempotency_key')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key'], 'debt_movements_user_idempotency_uq');
            $table->index(['user_id', 'occurred_at'], 'debt_movements_user_date_idx');
            $table->index(['debt_id', 'occurred_at'], 'debt_movements_debt_date_idx');
        });

        $now = now();
        DB::table('debts')->where('outstanding_amount', '>', 0)->orderBy('id')->chunk(500, function ($debts) use ($now) {
            foreach ($debts as $debt) {
                DB::table('debt_balance_movements')->insert([
                    'user_id' => $debt->user_id,
                    'debt_id' => $debt->id,
                    'kind' => 'opening_balance',
                    'delta' => $debt->outstanding_amount,
                    'description' => 'Balance at debt history activation',
                    'occurred_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE transactions DROP CHECK transactions_kind_chk');
            DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_kind_chk CHECK (kind IN ('income_receipt', 'commitment_payment', 'debt_payment', 'reversal'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE transactions DROP CHECK transactions_kind_chk');
            DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_kind_chk CHECK (kind IN ('income_receipt', 'commitment_payment', 'reversal'))");
        }

        Schema::dropIfExists('debt_balance_movements');
    }
};
