<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debt_balance_movements', function (Blueprint $table) {
            if (!Schema::hasColumn('debt_balance_movements', 'event_idempotency_key')) {
                $table->uuid('event_idempotency_key')->nullable()->after('idempotency_key');
                $table->index(['debt_id', 'event_idempotency_key'], 'debt_movements_event_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('debt_balance_movements', function (Blueprint $table) {
            $table->dropIndex('debt_movements_event_idx');
            $table->dropColumn('event_idempotency_key');
        });
    }
};
