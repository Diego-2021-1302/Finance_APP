<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialTariffRecord extends Model
{
    protected $fillable = [
        'financial_institution_id', 'financial_product_id', 'financial_product_variant_id',
        'fee_type', 'value', 'unit', 'currency', 'frequency', 'formula',
        'published_at', 'effective_from', 'effective_to', 'official_source_url',
        'source_document_hash', 'verification_status', 'exceptions_notes', 'version', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'published_at' => 'date',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'version' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(FinancialInstitution::class, 'financial_institution_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(FinancialProduct::class, 'financial_product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(FinancialProductVariant::class, 'financial_product_variant_id');
    }
}
