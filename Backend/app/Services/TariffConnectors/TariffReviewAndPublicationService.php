<?php

namespace App\Services\TariffConnectors;

use App\Models\FinancialProductVariant;
use App\Models\FinancialTariffRecord;
use Illuminate\Support\Carbon;

class TariffReviewAndPublicationService
{
    public function publish(int $institutionId, array $item, ?string $documentHash): FinancialTariffRecord
    {
        $productId = $item['product_id'] ?? null;
        $variantId = $item['variant_id'] ?? null;
        $feeType = $item['fee_type'];
        $newValue = $item['value'];
        $effectiveFrom = Carbon::parse($item['effective_from'] ?? now());
        $isPendingReview = $item['pending_review'] ?? false;

        $existing = FinancialTariffRecord::query()
            ->where('financial_institution_id', $institutionId)
            ->where('financial_product_id', $productId)
            ->where('financial_product_variant_id', $variantId)
            ->where('fee_type', $feeType)
            ->where('is_active', true)
            ->first();

        $latest = FinancialTariffRecord::query()
            ->where('financial_institution_id', $institutionId)
            ->where('financial_product_id', $productId)
            ->where('financial_product_variant_id', $variantId)
            ->where('fee_type', $feeType)
            ->orderByDesc('version')
            ->first();
        $nextVersion = $latest ? $latest->version + 1 : 1;

        $officialSource = $item['official_source_url'] ?? null;
        $status = $isPendingReview || !$officialSource || !$documentHash
            ? 'pending_review'
            : 'pending_review';

        if ($existing && $status === 'verified') {
            $existing->update([
                'is_active' => false,
                'effective_to' => $effectiveFrom->copy()->subDay(),
            ]);
        }

        $record = FinancialTariffRecord::create([
            'financial_institution_id' => $institutionId,
            'financial_product_id' => $productId,
            'financial_product_variant_id' => $variantId,
            'fee_type' => $feeType,
            'value' => $newValue,
            'unit' => $item['unit'] ?? 'percentage',
            'currency' => $item['currency'] ?? 'DOP',
            'frequency' => $item['frequency'] ?? 'annual',
            'published_at' => $item['published_at'] ?? now()->toDateString(),
            'effective_from' => $effectiveFrom->toDateString(),
            'official_source_url' => $item['official_source_url'] ?? null,
            'source_document_hash' => $documentHash,
            'verification_status' => $status,
            'exceptions_notes' => $item['exceptions_notes'] ?? null,
            'version' => $nextVersion,
            'is_active' => $status === 'verified',
        ]);

        if ($status === 'verified' && $variantId) {
            $variant = FinancialProductVariant::find($variantId);
            if ($variant) {
                if ($feeType === 'annual_interest_rate') {
                    $variant->update(['annual_interest_rate' => $newValue]);
                } elseif ($feeType === 'overdraft_fee_amount') {
                    $variant->update(['overdraft_fee_amount' => $newValue]);
                }
            }
        }

        return $record;
    }
}
