<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebtBalanceMovement extends Model
{
    protected $fillable = [
        'user_id', 'debt_id', 'account_id', 'kind', 'delta', 'description', 'occurred_at', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return ['delta' => 'decimal:2', 'occurred_at' => 'datetime'];
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
