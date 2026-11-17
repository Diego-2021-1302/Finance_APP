<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'user_id', 'income_id', 'commitment_occurrence_id', 'account_id', 'amount',
        'paid_at', 'idempotency_key', 'reversed_at', 'reversal_reason', 'reversal_key',
    ];
    protected function casts(): array { return ['amount' => 'decimal:2', 'paid_at' => 'datetime', 'reversed_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function income(): BelongsTo { return $this->belongsTo(Income::class); }
    public function occurrence(): BelongsTo { return $this->belongsTo(CommitmentOccurrence::class, 'commitment_occurrence_id'); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
}
