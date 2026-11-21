<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Commitment extends Model
{
    protected $fillable = [
        'user_id', 'account_id', 'debt_id', 'name', 'type', 'amount', 'frequency', 'next_due_date', 'is_active', 'notes',
        'reference_number', 'institution', 'opening_balance', 'current_balance', 'interest_rate', 'category', 'maturity_date',
        'statement_day', 'payment_grace_days', 'statement_balance', 'credit_limit',
        'approved_credit_limit', 'authorized_overdraft_limit', 'limit_status', 'overdraft_fee_type', 'overdraft_fee_amount', 'overdraft_fee_percentage', 'overdraft_fee_tax_rate',
        'cash_advance_interest_rate', 'moratorium_interest_rate', 'membership_fee_amount', 'insurance_fee_amount',
        'closing_fee_percentage', 'late_fee_percentage', 'late_fee_fixed', 'amortization_type', 'term_months',
        'promo_interest_rate', 'promo_until_date',
        'special_rules_documented', 'special_rules_source', 'special_rules_reference',
        'card_product_name', 'card_variant', 'card_network', 'card_currency', 'issued_at',
        'annual_fee', 'replacement_fee', 'cash_advance_fee', 'late_fee', 'card_benefits', 'card_payment_methods',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'opening_balance' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'statement_balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'approved_credit_limit' => 'decimal:2',
            'authorized_overdraft_limit' => 'decimal:2',
            'overdraft_fee_amount' => 'decimal:2',
            'overdraft_fee_percentage' => 'decimal:2',
            'overdraft_fee_tax_rate' => 'decimal:2',
            'interest_rate' => 'decimal:3',
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
            'statement_day' => 'integer',
            'payment_grace_days' => 'integer',
            'next_due_date' => 'date',
            'maturity_date' => 'date',
            'is_active' => 'boolean',
            'special_rules_documented' => 'boolean',
            'issued_at' => 'date',
            'annual_fee' => 'decimal:2',
            'replacement_fee' => 'decimal:2',
            'cash_advance_fee' => 'decimal:2',
            'late_fee' => 'decimal:2',
            'card_benefits' => 'array',
            'card_payment_methods' => 'array',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
    public function debt(): BelongsTo { return $this->belongsTo(Debt::class); }
    public function occurrences(): HasMany { return $this->hasMany(CommitmentOccurrence::class); }
    public function statements(): HasMany { return $this->hasMany(CreditCardStatement::class); }
}
