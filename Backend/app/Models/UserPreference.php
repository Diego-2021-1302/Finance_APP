<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    protected $fillable = ['user_id', 'currency', 'timezone', 'reminders', 'week_starts', 'appearance'];
    protected function casts(): array { return ['reminders' => 'integer']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
