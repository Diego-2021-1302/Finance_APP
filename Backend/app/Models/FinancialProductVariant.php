<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialProductVariant extends Model
{
    protected $fillable = [
        'financial_product_id', 'variant_name', 'min_credit_limit', 'max_credit_limit',
        'annual_interest_rate', 'overdraft_fee_type', 'overdraft_fee_amount',
        'overdraft_fee_percentage', 'overdraft_fee_tax_rate', 'grace_days',
        'cash_advance_interest_rate', 'moratorium_interest_rate', 'membership_fee_amount', 'insurance_fee_amount',
        'closing_fee_percentage', 'late_fee_percentage', 'late_fee_fixed', 'amortization_type', 'term_months',
        'promo_interest_rate', 'promo_until_date',
        'effective_from', 'effective_to', 'verified', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'min_credit_limit' => 'decimal:2',
            'max_credit_limit' => 'decimal:2',
            'annual_interest_rate' => 'decimal:3',
            'overdraft_fee_amount' => 'decimal:2',
            'overdraft_fee_percentage' => 'decimal:2',
            'overdraft_fee_tax_rate' => 'decimal:2',
            'cash_advance_interest_rate' => 'decimal:3',
            'moratorium_interest_rate' => 'decimal:3',
            'membership_fee_amount' => 'decimal:2',
            'insurance_fee_amount' => 'decimal:2',
            'closing_fee_percentage' => 'decimal:2',
            'late_fee_percentage' => 'decimal:2',
            'late_fee_fixed' => 'decimal:2',
            'term_months' => 'integer',
            'promo_interest_rate' => 'decimal:3',
            'promo_until_date' => 'date',
            'grace_days' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'verified' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(FinancialProduct::class, 'financial_product_id');
    }
}
