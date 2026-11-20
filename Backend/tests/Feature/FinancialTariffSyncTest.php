<?php

namespace Tests\Feature;

use App\Models\FinancialInstitution;
use App\Models\FinancialProduct;
use App\Models\FinancialProductVariant;
use App\Models\FinancialTariffRecord;
use App\Models\FinancialTariffSyncLog;
use App\Models\User;
use App\Services\FinancialTariffSyncService;
use App\Services\TariffConnectors\TariffDocumentDownloadService;
use Database\Seeders\FinancialCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialTariffSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinancialCatalogSeeder::class);
    }

    public function test_tariff_sync_service_creates_versioned_records_and_sync_logs(): void
    {
        $bpd = FinancialInstitution::where('sb_code', '101')->firstOrFail();
        $product = FinancialProduct::where('financial_institution_id', $bpd->id)->where('name', 'Visa Gold Popular')->firstOrFail();
        $variant = FinancialProductVariant::where('financial_product_id', $product->id)->firstOrFail();

        $syncService = app(FinancialTariffSyncService::class);

        // First sync: Extracted initial rate 60%
        $log1 = $syncService->syncInstitutionTariffs($bpd, [
            [
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'fee_type' => 'annual_interest_rate',
                'value' => '60.00',
                'unit' => 'percentage',
                'currency' => 'DOP',
                'effective_from' => '2026-01-01',
                'official_source_url' => 'https://popularenlinea.com/tarifas',
            ]
        ]);

        $this->assertEquals('pending_review', $log1->status);
        $this->assertEquals(1, $log1->changes_detected);

        $v1Record = FinancialTariffRecord::where('financial_product_variant_id', $variant->id)->where('version', 1)->firstOrFail();
        $this->assertEquals('60.0000', (string) $v1Record->value);
        $this->assertFalse($v1Record->is_active);
        $this->assertEquals('pending_review', $v1Record->verification_status);

        // Second sync: Rate updated to 62%
        $log2 = $syncService->syncInstitutionTariffs($bpd, [
            [
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'fee_type' => 'annual_interest_rate',
                'value' => '62.00',
                'unit' => 'percentage',
                'currency' => 'DOP',
                'effective_from' => '2026-06-01',
                'official_source_url' => 'https://popularenlinea.com/tarifas',
            ]
        ]);

        $this->assertEquals('pending_review', $log2->status);
        $this->assertEquals(1, $log2->changes_detected);

        $v1Record->refresh();
        $this->assertFalse($v1Record->is_active);

        $v2Record = FinancialTariffRecord::where('financial_product_variant_id', $variant->id)->where('version', 2)->firstOrFail();
        $this->assertEquals('62.0000', (string) $v2Record->value);
        $this->assertFalse($v2Record->is_active);
        $this->assertEquals('pending_review', $v2Record->verification_status);
    }

    public function test_artisan_sync_command_runs_successfully(): void
    {
        $this->artisan('finance:sync-tariffs')
            ->assertExitCode(0);

    }

    public function test_download_rejects_non_https_and_unapproved_domains(): void
    {
        $service = app(TariffDocumentDownloadService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Solo se permiten fuentes HTTPS');
        $service->downloadAndHash('http://popularenlinea.com/tarifas');
    }

    public function test_unapproved_domain_is_rejected_before_download(): void
    {
        $service = app(TariffDocumentDownloadService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dominio no autorizado');
        $service->downloadAndHash('https://example.com/tarifas');
    }

    public function test_can_query_tariffs_and_sync_logs_via_api(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $bpd = FinancialInstitution::where('sb_code', '101')->firstOrFail();
        $syncService = app(FinancialTariffSyncService::class);
        $syncService->syncInstitutionTariffs($bpd, []);

        // Query tariffs
        $tariffRes = $this->withToken($token)->getJson('/api/v1/catalog/tariffs');
        $tariffRes->assertOk();

        // Query sync logs
        $logRes = $this->withToken($token)->getJson('/api/v1/catalog/sync-logs');
        $logRes->assertOk();
        $this->assertNotEmpty($logRes->json('data'));
    }
}
