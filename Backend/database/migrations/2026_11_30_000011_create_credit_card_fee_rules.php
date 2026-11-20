<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_fee_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_institution_id')->nullable()->constrained('financial_institutions')->nullOnDelete();
            $table->foreignId('financial_product_id')->nullable()->constrained('financial_products')->nullOnDelete();
            $table->foreignId('financial_product_variant_id')->nullable()->constrained('financial_product_variants')->nullOnDelete();
            $table->string('trigger', 32);
            $table->string('formula_type', 32);
            $table->decimal('formula_value', 15, 4)->default(0);
            $table->decimal('threshold_amount', 15, 2)->nullable();
            $table->string('frequency', 32)->nullable();
            $table->json('exemptions')->nullable();
            $table->decimal('maximum_amount', 15, 2)->nullable();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('source_url', 2048);
            $table->string('verification_status', 32)->default('pending');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['verification_status', 'is_active', 'effective_from', 'effective_to'], 'fee_rules_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_fee_rules');
    }
};
