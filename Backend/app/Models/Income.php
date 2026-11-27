<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Income extends Model
{
    protected $fillable = ['user_id', 'source', 'amount', 'expected_date', 'notes', 'frequency', 'recurrence_end_date', 'recurrence_key', 'recurrence_day', 'recurrence_second_day'];
    protected function casts(): array { return ['amount' => 'decimal:2', 'expected_date' => 'date', 'recurrence_end_date' => 'date']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
}
