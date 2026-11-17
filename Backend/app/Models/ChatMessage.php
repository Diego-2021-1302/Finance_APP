<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $fillable = [
        'user_id',
        'role',
        'content',
        'actions_taken',
        'pending_action',
    ];

    protected function casts(): array
    {
        return [
            'actions_taken' => 'array',
            'pending_action' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
