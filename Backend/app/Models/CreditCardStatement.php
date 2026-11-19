<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditCardStatement extends Model
{
    protected $fillable = [
        'user_id', 'commitment_id', 'debt_id', 'statement_date', 'closing_balance', 'due_date',
        'minimum_payment', 'minimum_payment_confirmed', 'minimum_payment_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'closing_balance' => 'decimal:2',
            'due_date' => 'date',
            'minimum_payment' => 'decimal:2',
            'minimum_payment_confirmed' => 'boolean',
            'minimum_payment_confirmed_at' => 'datetime',
        ];
    }

    public function commitment(): BelongsTo
    {
        return $this->belongsTo(Commitment::class);
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }
}
