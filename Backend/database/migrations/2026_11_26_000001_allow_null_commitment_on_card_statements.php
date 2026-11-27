<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE credit_card_statements MODIFY commitment_id BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('credit_card_statements')->whereNull('commitment_id')->delete();
            DB::statement('ALTER TABLE credit_card_statements MODIFY commitment_id BIGINT UNSIGNED NOT NULL');
        }
    }
};
