<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_tariff_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_institution_id')->constrained('financial_institutions')->cascadeOnDelete();
            $table->foreignId('financial_product_id')->constrained('financial_products')->cascadeOnDelete();
            $table->foreignId('financial_product_variant_id')->nullable()->constrained('financial_product_variants')->nullOnDelete();
            $table->string('fee_type', 64);
            $table->decimal('value', 15, 4);
            $table->string('unit', 32)->default('currency_amount');
            $table->char('currency', 3)->default('DOP');
            $table->string('frequency', 32)->default('per_event');
            $table->string('formula', 500)->nullable();
            $table->date('published_at');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('official_source_url', 500)->nullable();
            $table->string('source_document_hash', 128)->nullable();
            $table->string('verification_status', 32)->default('verified');
            $table->text('exceptions_notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['financial_institution_id', 'fee_type', 'is_active'], 'tariffs_institution_type_active_idx');
            $table->index(['effective_from', 'effective_to'], 'tariffs_effective_dates_idx');
        });

        Schema::create('financial_tariff_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_institution_id')->constrained('financial_institutions')->cascadeOnDelete();
            $table->string('connector_type', 32)->default('public_page');
            $table->string('source_url', 500);
            $table->string('status', 32)->default('success');
            $table->unsignedInteger('changes_detected')->default(0);
            $table->text('error_message')->nullable();
            $table->dateTime('sync_started_at');
            $table->dateTime('sync_completed_at')->nullable();
            $table->timestamps();

            $table->index(['financial_institution_id', 'status', 'created_at'], 'sync_logs_institution_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_tariff_sync_logs');
        Schema::dropIfExists('financial_tariff_records');
    }
};
