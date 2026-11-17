<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialTransaction extends Model
{
    protected $table = 'transactions';
    protected $fillable = ['user_id', 'account_id', 'payment_id', 'description', 'amount', 'occurred_at', 'kind', 'reversal_of_id'];
    protected function casts(): array { return ['amount' => 'decimal:2', 'occurred_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function reversalOf(): BelongsTo { return $this->belongsTo(self::class, 'reversal_of_id'); }
}
