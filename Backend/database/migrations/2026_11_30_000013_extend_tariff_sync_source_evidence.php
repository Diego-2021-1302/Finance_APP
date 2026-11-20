<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_tariff_sync_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('financial_tariff_sync_logs', 'document_hash')) {
                $table->string('document_hash', 64)->nullable()->after('source_url');
            }
            if (!Schema::hasColumn('financial_tariff_sync_logs', 'document_path')) {
                $table->string('document_path', 2048)->nullable()->after('document_hash');
            }
            if (!Schema::hasColumn('financial_tariff_sync_logs', 'document_type')) {
                $table->string('document_type', 128)->nullable()->after('document_path');
            }
            if (!Schema::hasColumn('financial_tariff_sync_logs', 'access_status')) {
                $table->string('access_status', 32)->default('not_attempted')->after('document_type');
            }
            if (!Schema::hasColumn('financial_tariff_sync_logs', 'discovered_at')) {
                $table->dateTime('discovered_at')->nullable()->after('access_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('financial_tariff_sync_logs', function (Blueprint $table) {
            $table->dropColumn([
                'document_hash',
                'document_path',
                'document_type',
                'access_status',
                'discovered_at',
            ]);
        });
    }
};
