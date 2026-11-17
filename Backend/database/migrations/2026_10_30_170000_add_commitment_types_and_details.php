<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commitments', function (Blueprint $table) {
            $table->string('type', 32)->default('other')->after('name');
            $table->string('reference_number', 80)->nullable()->after('next_due_date');
            $table->string('institution', 160)->nullable()->after('reference_number');
            $table->decimal('current_balance', 15, 2)->nullable()->after('institution');
            $table->decimal('interest_rate', 6, 3)->nullable()->after('current_balance');
            $table->string('category', 80)->nullable()->after('interest_rate');
            $table->date('maturity_date')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('commitments', function (Blueprint $table) {
            $table->dropColumn([
                'type', 'reference_number', 'institution', 'current_balance',
                'interest_rate', 'category', 'maturity_date',
            ]);
        });
    }
};
