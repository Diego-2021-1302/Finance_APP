<?php

namespace App\Services\TariffConnectors;

class TariffNormalizationService
{
    public function normalize(array $extractedItem): array
    {
        return [
            'product_id' => $extractedItem['product_id'] ?? null,
            'variant_id' => $extractedItem['variant_id'] ?? null,
            'fee_type' => $extractedItem['fee_type'] ?? 'annual_interest_rate',
            'value' => $this->normalizeDecimal((string) ($extractedItem['value'] ?? '0'), 4),
            'unit' => $extractedItem['unit'] ?? 'percentage',
            'currency' => $extractedItem['currency'] ?? 'DOP',
            'frequency' => $extractedItem['frequency'] ?? 'annual',
            'effective_from' => $extractedItem['effective_from'] ?? now()->toDateString(),
            'official_source_url' => $extractedItem['official_source_url'] ?? null,
            'exceptions_notes' => $extractedItem['exceptions_notes'] ?? null,
            'pending_review' => $extractedItem['pending_review'] ?? false,
        ];
    }

    private function normalizeDecimal(string $value, int $scale): string
    {
        $clean = str_replace([' ', ','], ['', '.'], trim($value));
        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $clean)) {
            return '0.0000';
        }
        $negative = str_starts_with($clean, '-');
        $clean = ltrim($clean, '-');
        [$whole, $fraction] = array_pad(explode('.', $clean, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $result = $whole . '.' . $fraction;
        return ($negative ? '-' : '') . $result;
    }
}
