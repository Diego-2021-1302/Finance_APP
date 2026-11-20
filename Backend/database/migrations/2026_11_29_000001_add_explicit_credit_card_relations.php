<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commitments', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('user_id')->constrained('accounts')->nullOnDelete();
            $table->foreignId('debt_id')->nullable()->after('account_id')->constrained('debts')->nullOnDelete();
        });

        Schema::table('debts', function (Blueprint $table) {
            $table->foreignId('commitment_id')->nullable()->after('account_id')->constrained('commitments')->nullOnDelete();
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('commitment_id')->nullable()->after('user_id')->constrained('commitments')->nullOnDelete();
            $table->foreignId('debt_id')->nullable()->after('commitment_id')->constrained('debts')->nullOnDelete();
        });

        // Incremental backfill: Populate explicit foreign keys for existing credit cards and loans
        $commitments = DB::table('commitments')
            ->whereIn('type', ['credit_card', 'loan'])
            ->get();

        foreach ($commitments as $commitment) {
            $account = DB::table('accounts')
                ->where('user_id', $commitment->user_id)
                ->where('name', $commitment->name)
                ->first();

            $debt = DB::table('debts')
                ->where('user_id', $commitment->user_id)
                ->where('name', $commitment->name)
                ->first();

            if ($account && !$account->debt_id && $debt) {
                DB::table('accounts')->where('id', $account->id)->update(['debt_id' => $debt->id, 'commitment_id' => $commitment->id]);
            }

            if ($debt && !$debt->commitment_id) {
                DB::table('debts')->where('id', $debt->id)->update(['commitment_id' => $commitment->id, 'account_id' => $account?->id]);
            }

            DB::table('commitments')
                ->where('id', $commitment->id)
                ->update([
                    'account_id' => $account?->id,
                    'debt_id' => $debt?->id,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['commitment_id']);
            $table->dropForeign(['debt_id']);
            $table->dropColumn(['commitment_id', 'debt_id']);
        });

        Schema::table('debts', function (Blueprint $table) {
            $table->dropForeign(['commitment_id']);
            $table->dropColumn(['commitment_id']);
        });

        Schema::table('commitments', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['debt_id']);
            $table->dropColumn(['account_id', 'debt_id']);
        });
    }
};
