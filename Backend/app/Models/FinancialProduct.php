<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialProduct extends Model
{
    protected $fillable = [
        'financial_institution_id', 'name', 'category', 'currency', 'verified', 'official_source', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(FinancialInstitution::class, 'financial_institution_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(FinancialProductVariant::class);
    }
}
