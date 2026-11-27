<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incomes', function (Blueprint $table) {
            $table->string('frequency', 32)->default('once')->after('expected_date');
            $table->date('recurrence_end_date')->nullable()->after('frequency');
            $table->string('recurrence_key', 64)->nullable()->after('recurrence_end_date');
            $table->unsignedTinyInteger('recurrence_day')->nullable()->after('recurrence_key');
            $table->unsignedTinyInteger('recurrence_second_day')->nullable()->after('recurrence_day');
            $table->unique(['recurrence_key', 'expected_date'], 'incomes_recurrence_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('incomes', function (Blueprint $table) {
            $table->dropUnique('incomes_recurrence_date_unique');
            $table->dropColumn(['frequency', 'recurrence_end_date', 'recurrence_key', 'recurrence_day', 'recurrence_second_day']);
        });
    }
};
