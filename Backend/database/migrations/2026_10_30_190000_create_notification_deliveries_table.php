<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commitment_occurrence_id')->constrained('commitment_occurrences')->cascadeOnDelete();
            $table->string('type', 32)->default('due_reminder');
            $table->date('scheduled_date');
            $table->string('status', 24)->default('sent');
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('sent_at')->useCurrent();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'commitment_occurrence_id', 'type'], 'notification_deliveries_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
