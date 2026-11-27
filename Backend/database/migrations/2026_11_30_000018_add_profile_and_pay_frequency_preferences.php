<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            $table->string('pay_frequency', 16)->default('monthly');
            $table->string('profile_photo_path')->nullable();
            $table->string('piggy_bank_photo_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            $table->dropColumn(['pay_frequency', 'profile_photo_path', 'piggy_bank_photo_path']);
        });
    }
};