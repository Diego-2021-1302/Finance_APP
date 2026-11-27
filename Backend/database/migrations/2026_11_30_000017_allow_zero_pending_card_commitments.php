<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE commitments DROP CHECK commitments_amount_positive_chk');
        DB::statement("ALTER TABLE commitments ADD CONSTRAINT commitments_amount_positive_chk CHECK (amount > 0 OR (type = 'credit_card' AND amount = 0))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('commitments')
            ->where('type', 'credit_card')
            ->where('amount', 0)
            ->update(['amount' => '0.01']);
        DB::statement('ALTER TABLE commitments DROP CHECK commitments_amount_positive_chk');
        DB::statement('ALTER TABLE commitments ADD CONSTRAINT commitments_amount_positive_chk CHECK (amount > 0)');
    }
};