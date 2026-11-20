<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_institutions', function (Blueprint $table) {
            $table->id();
            $table->string('sb_code', 32)->nullable()->unique();
            $table->string('name', 160);
            $table->string('type', 64)->default('banco_multiple');
            $table->string('logo_url', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });

        Schema::create('financial_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_institution_id')->constrained('financial_institutions')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('category', 64);
            $table->char('currency', 3)->default('DOP');
            $table->boolean('verified')->default(true);
            $table->string('official_source', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['financial_institution_id', 'category', 'is_active'], 'products_institution_category_idx');
        });

        Schema::create('financial_product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_product_id')->constrained('financial_products')->cascadeOnDelete();
            $table->string('variant_name', 160);
            $table->decimal('min_credit_limit', 15, 2)->nullable();
            $table->decimal('max_credit_limit', 15, 2)->nullable();
            $table->decimal('annual_interest_rate', 6, 3)->nullable();
            $table->string('overdraft_fee_type', 32)->default('none');
            $table->decimal('overdraft_fee_amount', 15, 2)->default(0.00);
            $table->decimal('overdraft_fee_percentage', 5, 2)->default(0.00);
            $table->decimal('overdraft_fee_tax_rate', 5, 2)->default(0.00);
            $table->unsignedTinyInteger('grace_days')->default(21);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('verified')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('financial_product_id');
        });

        Schema::table('commitments', function (Blueprint $table) {
            $table->foreignId('financial_institution_id')->nullable()->after('debt_id')->constrained('financial_institutions')->nullOnDelete();
            $table->foreignId('financial_product_variant_id')->nullable()->after('financial_institution_id')->constrained('financial_product_variants')->nullOnDelete();
        });

        Schema::table('debts', function (Blueprint $table) {
            $table->foreignId('financial_institution_id')->nullable()->after('commitment_id')->constrained('financial_institutions')->nullOnDelete();
            $table->foreignId('financial_product_variant_id')->nullable()->after('financial_institution_id')->constrained('financial_product_variants')->nullOnDelete();
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('financial_institution_id')->nullable()->after('debt_id')->constrained('financial_institutions')->nullOnDelete();
            $table->foreignId('financial_product_variant_id')->nullable()->after('financial_institution_id')->constrained('financial_product_variants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['financial_institution_id']);
            $table->dropForeign(['financial_product_variant_id']);
            $table->dropColumn(['financial_institution_id', 'financial_product_variant_id']);
        });

        Schema::table('debts', function (Blueprint $table) {
            $table->dropForeign(['financial_institution_id']);
            $table->dropForeign(['financial_product_variant_id']);
            $table->dropColumn(['financial_institution_id', 'financial_product_variant_id']);
        });

        Schema::table('commitments', function (Blueprint $table) {
            $table->dropForeign(['financial_institution_id']);
            $table->dropForeign(['financial_product_variant_id']);
            $table->dropColumn(['financial_institution_id', 'financial_product_variant_id']);
        });

        Schema::dropIfExists('financial_product_variants');
        Schema::dropIfExists('financial_products');
        Schema::dropIfExists('financial_institutions');
    }
};
