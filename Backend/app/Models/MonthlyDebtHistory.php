<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyDebtHistory extends Model
{
    protected $table = 'monthly_debt_history';

    protected $fillable = [
        'user_id', 'month', 'total_debt', 'card_debt', 'loan_debt',
        'other_debt', 'captured_at', 'source',
    ];

    protected function casts(): array
    {
        return [
            'total_debt' => 'decimal:2',
            'card_debt' => 'decimal:2',
            'loan_debt' => 'decimal:2',
            'other_debt' => 'decimal:2',
            'captured_at' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
