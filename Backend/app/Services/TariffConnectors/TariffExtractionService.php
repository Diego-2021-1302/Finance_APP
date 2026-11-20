<?php

namespace App\Services\TariffConnectors;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class TariffExtractionService
{
    public function extract(string $documentHash, array $rawTariffData = [], ?string $documentPath = null, ?string $documentType = null): array
    {
        $documentText = null;

        if ($rawTariffData !== []) {
            $documentText = 'Datos estructurados proporcionados explícitamente';
        } elseif ($documentPath && Storage::disk('local')->exists($documentPath)) {
            $bytes = Storage::disk('local')->get($documentPath);
            if ($documentType === 'application/pdf') {
                $tempPath = storage_path('app/tariff-extract-' . $documentHash . '.pdf');
                file_put_contents($tempPath, $bytes);
                $result = Process::timeout(20)->run(['pdftotext', '-layout', $tempPath, '-']);
                @unlink($tempPath);
                if (!$result->successful()) {
                    throw new RuntimeException('No fue posible extraer texto del PDF descargado.');
                }
                $documentText = $result->output();
            } else {
                $documentText = trim(preg_replace('/\s+/', ' ', strip_tags($bytes)));
            }
        }

        return [
            'document_hash' => $documentHash,
            'extracted_items' => $rawTariffData,
            'document_text' => $documentText,
            'requires_manual_review' => $rawTariffData === [],
            'extracted_at' => now()->toIso8601String(),
        ];
    }
}
