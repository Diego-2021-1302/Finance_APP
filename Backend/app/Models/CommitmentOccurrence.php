<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommitmentOccurrence extends Model
{
    protected $fillable = ['user_id', 'commitment_id', 'amount_due', 'due_date', 'status', 'schedule_version', 'interest_accrued'];
    protected function casts(): array { return ['amount_due' => 'decimal:2', 'interest_accrued' => 'decimal:2', 'due_date' => 'date']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function commitment(): BelongsTo { return $this->belongsTo(Commitment::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
}
