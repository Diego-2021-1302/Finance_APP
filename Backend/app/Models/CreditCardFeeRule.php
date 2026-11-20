<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditCardFeeRule extends Model
{
    protected $fillable = [
        'financial_institution_id',
        'financial_product_id',
        'financial_product_variant_id',
        'trigger',
        'formula_type',
        'formula_value',
        'threshold_amount',
        'frequency',
        'exemptions',
        'maximum_amount',
        'tax_rate',
        'effective_from',
        'effective_to',
        'source_url',
        'verification_status',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'formula_value' => 'decimal:4',
            'threshold_amount' => 'decimal:2',
            'exemptions' => 'array',
            'maximum_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
