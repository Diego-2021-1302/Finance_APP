<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialTariffSyncLog extends Model
{
    protected $fillable = [
        'financial_institution_id', 'connector_type', 'source_url', 'status',
        'changes_detected', 'error_message', 'sync_started_at', 'sync_completed_at',
        'document_hash', 'document_path', 'document_type', 'access_status', 'discovered_at',
    ];

    protected function casts(): array
    {
        return [
            'changes_detected' => 'integer',
            'sync_started_at' => 'datetime',
            'sync_completed_at' => 'datetime',
            'discovered_at' => 'datetime',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(FinancialInstitution::class, 'financial_institution_id');
    }
}
