<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commitments', function (Blueprint $table) {
            $table->decimal('opening_balance', 15, 2)->nullable()->after('institution');
        });

        Schema::table('commitment_occurrences', function (Blueprint $table) {
            $table->decimal('interest_accrued', 15, 2)->nullable()->after('amount_due');
        });
    }

    public function down(): void
    {
        Schema::table('commitment_occurrences', function (Blueprint $table) {
            $table->dropColumn('interest_accrued');
        });

        Schema::table('commitments', function (Blueprint $table) {
            $table->dropColumn('opening_balance');
        });
    }
};
