<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE transactions DROP CHECK transactions_kind_chk');
            DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_kind_chk CHECK (kind IN ('income_receipt', 'commitment_payment', 'debt_payment', 'account_opening', 'reversal'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("DELETE FROM transactions WHERE kind = 'account_opening'");
            DB::statement('ALTER TABLE transactions DROP CHECK transactions_kind_chk');
            DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_kind_chk CHECK (kind IN ('income_receipt', 'commitment_payment', 'debt_payment', 'reversal'))");
        }
    }
};
