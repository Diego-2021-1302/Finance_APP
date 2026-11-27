<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            if (!Schema::hasColumn('debts', 'reference_number')) {
                $table->string('reference_number', 80)->nullable()->after('last_four');
            }
            if (!Schema::hasColumn('debts', 'institution')) {
                $table->string('institution', 160)->nullable()->after('reference_number');
            }
            if (!Schema::hasColumn('debts', 'maturity_date')) {
                $table->date('maturity_date')->nullable()->after('institution');
            }
        });
    }

    public function down(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            $table->dropColumn(['reference_number', 'institution', 'maturity_date']);
        });
    }
};
