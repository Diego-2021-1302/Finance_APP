<?php

namespace App\Services\TariffConnectors;

use App\Models\FinancialInstitution;
use App\Models\FinancialProduct;

class TariffSourceDiscoveryService
{
    public function discoverInstitution(FinancialInstitution $institution): array
    {
        $sources = FinancialProduct::query()
            ->where('financial_institution_id', $institution->id)
            ->where('is_active', true)
            ->whereNotNull('official_source')
            ->orderBy('name')
            ->get(['id', 'name', 'category', 'official_source']);

        $discovered = $sources->map(fn (FinancialProduct $product) => [
            'institution_id' => $institution->id,
            'institution_name' => $institution->name,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'category' => $product->category,
            'url' => $product->official_source,
            'document_type' => $this->guessDocumentType($product->official_source),
            'discovered_at' => now()->toIso8601String(),
            'access_status' => 'pending',
        ]);

        return [
            'sb_code' => $institution->sb_code,
            'institution_name' => $institution->name,
            'connector_type' => 'official_public_source',
            'sources' => $discovered->values()->all(),
        ];
    }

    public function discover(string $sbCode, string $institutionName): array
    {
        return [
            'sb_code' => $sbCode,
            'institution_name' => $institutionName,
            'connector_type' => 'official_public_source',
            'sources' => [],
            'discovered_at' => now()->toIso8601String(),
        ];
    }

    private function guessDocumentType(?string $url): string
    {
        $path = strtolower(parse_url((string) $url, PHP_URL_PATH) ?? '');
        return str_ends_with($path, '.pdf') ? 'application/pdf' : 'text/html';
    }
}
