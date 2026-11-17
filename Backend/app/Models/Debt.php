<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Debt extends Model
{
    protected $fillable = [
        'user_id', 'account_id', 'name', 'kind', 'outstanding_amount', 'minimum_payment', 'due_date', 'last_four',
        'statement_day', 'payment_grace_days', 'statement_balance',
    ];
    protected function casts(): array
    {
        return [
            'outstanding_amount' => 'decimal:2',
            'minimum_payment' => 'decimal:2',
            'statement_balance' => 'decimal:2',
            'statement_day' => 'integer',
            'payment_grace_days' => 'integer',
            'due_date' => 'date',
        ];
    }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
}
