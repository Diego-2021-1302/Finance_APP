<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transfer extends Model
{
    protected $table = 'account_transfers';

    protected $fillable = [
        'user_id',
        'source_account_id',
        'destination_account_id',
        'amount',
        'transferred_at',
        'notes',
        'idempotency_key',
        'reversed_at',
        'reversal_reason',
        'reversal_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transferred_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'source_account_id');
    }

    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'destination_account_id');
    }
}
