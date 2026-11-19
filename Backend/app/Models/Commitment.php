<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Commitment extends Model
{
    protected $fillable = [
        'user_id', 'name', 'type', 'amount', 'frequency', 'next_due_date', 'is_active', 'notes',
        'reference_number', 'institution', 'opening_balance', 'current_balance', 'interest_rate', 'category', 'maturity_date',
        'statement_day', 'payment_grace_days', 'statement_balance',
    ];
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'opening_balance' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'statement_balance' => 'decimal:2',
            'interest_rate' => 'decimal:3',
            'statement_day' => 'integer',
            'payment_grace_days' => 'integer',
            'next_due_date' => 'date',
            'maturity_date' => 'date',
            'is_active' => 'boolean',
        ];
    }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function occurrences(): HasMany { return $this->hasMany(CommitmentOccurrence::class); }
    public function statements(): HasMany { return $this->hasMany(CreditCardStatement::class); }
}
