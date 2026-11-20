<?php

namespace App\Services;

use App\Models\FinancialInstitution;
use App\Models\FinancialTariffSyncLog;
use App\Services\TariffConnectors\TariffSourceDiscoveryService;
use App\Services\TariffConnectors\TariffDocumentDownloadService;
use App\Services\TariffConnectors\TariffExtractionService;
use App\Services\TariffConnectors\TariffNormalizationService;
use App\Services\TariffConnectors\TariffValidationService;
use App\Services\TariffConnectors\TariffChangeDetectionService;
use App\Services\TariffConnectors\TariffReviewAndPublicationService;
use App\Services\TariffConnectors\TariffAlertService;
use Illuminate\Support\Facades\DB;

class FinancialTariffSyncService
{
    public function __construct(
        private readonly TariffSourceDiscoveryService $discovery,
        private readonly TariffDocumentDownloadService $download,
        private readonly TariffExtractionService $extraction,
        private readonly TariffNormalizationService $normalization,
        private readonly TariffValidationService $validation,
        private readonly TariffChangeDetectionService $changeDetection,
        private readonly TariffReviewAndPublicationService $publication,
        private readonly TariffAlertService $alert
    ) {}

    public function syncInstitutionTariffs(FinancialInstitution $institution, array $extractedTariffData = []): FinancialTariffSyncLog
    {
        $syncStartedAt = now();
        $discovery = $this->discovery->discoverInstitution($institution);
        $sources = $discovery['sources'];

        if (!$institution->is_active) {
            $this->alert->logAlert($institution->id, 'Institución inactiva.');
            return FinancialTariffSyncLog::create([
                'financial_institution_id' => $institution->id,
                'connector_type' => $discovery['connector_type'],
                'source_url' => $sources[0]['url'] ?? 'https://sb.gob.do/',
                'status' => 'pending',
                'document_hash' => null,
                'document_path' => null,
                'document_type' => null,
                'access_status' => 'institution_inactive',
                'discovered_at' => now(),
                'changes_detected' => 0,
                'error_message' => 'Institución inactiva.',
                'sync_started_at' => $syncStartedAt,
                'sync_completed_at' => now(),
            ]);
        }

        if ($extractedTariffData !== []) {
            $sourceUrl = (string) ($extractedTariffData[0]['official_source_url'] ?? $sources[0]['url'] ?? 'https://sb.gob.do/');
            $extracted = $this->extraction->extract('', $extractedTariffData);
            return $this->processExtractedItems(
                $institution,
                $discovery,
                $sourceUrl,
                $extracted,
                null,
                null,
                'data_explicit_no_document',
                $syncStartedAt,
            );
        }

        if ($sources === []) {
            $this->alert->logAlert($institution->id, 'No existen fuentes oficiales configuradas.');
            return FinancialTariffSyncLog::create([
                'financial_institution_id' => $institution->id,
                'connector_type' => $discovery['connector_type'],
                'source_url' => 'https://sb.gob.do/',
                'status' => 'pending',
                'document_hash' => null,
                'document_path' => null,
                'document_type' => null,
                'access_status' => 'no_source_configured',
                'discovered_at' => now(),
                'changes_detected' => 0,
                'error_message' => 'No existen fuentes oficiales configuradas para esta institución.',
                'sync_started_at' => $syncStartedAt,
                'sync_completed_at' => now(),
            ]);
        }

        $lastLog = null;
        foreach ($sources as $source) {
            try {
                $downloadResult = $this->download->downloadAndHash($source['url']);
                $extracted = $this->extraction->extract(
                    $downloadResult['document_hash'],
                    [],
                    $downloadResult['document_path'],
                    $downloadResult['document_type'],
                );
                $lastLog = $this->processExtractedItems(
                    $institution,
                    $discovery,
                    $source['url'],
                    $extracted,
                    $downloadResult['document_hash'],
                    $downloadResult['document_path'],
                    'downloaded',
                    $syncStartedAt,
                    $downloadResult['document_type'],
                );
            } catch (\Throwable $exception) {
                $lastLog = FinancialTariffSyncLog::create([
                    'financial_institution_id' => $institution->id,
                    'connector_type' => $discovery['connector_type'],
                    'source_url' => $source['url'],
                    'status' => 'failed',
                    'document_hash' => null,
                    'document_path' => null,
                    'document_type' => $source['document_type'],
                    'access_status' => 'download_failed',
                    'discovered_at' => $source['discovered_at'],
                    'changes_detected' => 0,
                    'error_message' => $exception->getMessage(),
                    'sync_started_at' => $syncStartedAt,
                    'sync_completed_at' => now(),
                ]);
                $this->alert->logAlert($institution->id, $exception->getMessage());
            }
        }

        return $lastLog ?? FinancialTariffSyncLog::create([
            'financial_institution_id' => $institution->id,
            'connector_type' => $discovery['connector_type'],
            'source_url' => $sources[0]['url'],
            'status' => 'failed',
            'document_hash' => null,
            'document_path' => null,
            'document_type' => null,
            'access_status' => 'download_failed',
            'discovered_at' => now(),
            'changes_detected' => 0,
            'error_message' => 'No se pudo descargar ninguna fuente oficial.',
            'sync_started_at' => $syncStartedAt,
            'sync_completed_at' => now(),
        ]);
    }

    private function processExtractedItems(
        FinancialInstitution $institution,
        array $discovery,
        string $sourceUrl,
        array $extracted,
        ?string $documentHash,
        ?string $documentPath,
        string $accessStatus,
        $syncStartedAt,
        ?string $documentType = null,
    ): FinancialTariffSyncLog {
        $changesDetected = 0;

        DB::transaction(function () use ($institution, $extracted, $documentHash, &$changesDetected) {
            foreach ($extracted['extracted_items'] as $rawItem) {
                $normalized = $this->normalization->normalize($rawItem);
                if (!$this->validation->validate($normalized)) {
                    continue;
                }

                $hasChanged = $this->changeDetection->detectChange(
                    $institution->id,
                    $normalized['product_id'],
                    $normalized['variant_id'],
                    $normalized['fee_type'],
                    $normalized['value']
                );

                if (!$hasChanged) {
                    continue;
                }

                $this->publication->publish($institution->id, $normalized, $documentHash);
                $changesDetected++;
            }
        });

        return FinancialTariffSyncLog::create([
            'financial_institution_id' => $institution->id,
            'connector_type' => $discovery['connector_type'],
            'source_url' => $sourceUrl,
            'status' => $changesDetected > 0 ? 'pending_review' : 'success',
            'document_hash' => $documentHash,
            'document_path' => $documentPath,
            'document_type' => $documentType,
            'access_status' => $accessStatus,
            'discovered_at' => now(),
            'changes_detected' => $changesDetected,
            'error_message' => $changesDetected > 0 ? 'Cambios extraídos; requieren revisión y aprobación.' : null,
            'sync_started_at' => $syncStartedAt,
            'sync_completed_at' => now(),
        ]);
    }
}
