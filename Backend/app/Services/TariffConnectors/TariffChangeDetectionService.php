<?php

namespace App\Services\TariffConnectors;

use App\Models\FinancialTariffRecord;

class TariffChangeDetectionService
{
    public function detectChange(int $institutionId, ?int $productId, ?int $variantId, string $feeType, string $newValue): bool
    {
        $existing = FinancialTariffRecord::query()
            ->where('financial_institution_id', $institutionId)
            ->where('financial_product_id', $productId)
            ->where('financial_product_variant_id', $variantId)
            ->where('fee_type', $feeType)
            ->where('is_active', true)
            ->first();

        if (!$existing) {
            return true;
        }

        return bccomp((string) $existing->value, $newValue, 4) !== 0;
    }
}
