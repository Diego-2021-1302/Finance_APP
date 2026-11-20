<?php

namespace App\Services\TariffConnectors;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class TariffDocumentDownloadService
{
    public function downloadAndHash(string $url): array
    {
        $this->assertSafeOfficialUrl($url);

        $response = Http::withOptions([
            'allow_redirects' => false,
            'timeout' => 15,
            'connect_timeout' => 5,
        ])
            ->retry(2, 500, throw: false)
            ->get($url);

        if (!$response->successful()) {
            throw new RuntimeException(sprintf(
                'No se pudo descargar la fuente oficial (HTTP %d).',
                $response->status(),
            ));
        }

        $bytes = $response->body();
        if (strlen($bytes) > 10 * 1024 * 1024) {
            throw new RuntimeException('El documento excede el límite seguro de 10 MB.');
        }
        if ($bytes === '') {
            throw new RuntimeException('La fuente oficial devolvió un documento vacío.');
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        $mime = trim(explode(';', $contentType)[0]);
        if (!in_array($mime, ['text/html', 'application/pdf', 'application/octet-stream'], true)) {
            throw new RuntimeException("Tipo de documento no permitido: {$mime}");
        }

        $documentHash = hash('sha256', $bytes);
        $extension = $mime === 'application/pdf' ? 'pdf' : 'html';
        $path = "tariff-documents/{$documentHash}.{$extension}";
        Storage::disk('local')->put($path, $bytes);

        return [
            'url' => $url,
            'document_hash' => $documentHash,
            'document_path' => $path,
            'document_type' => $mime,
            'downloaded_at' => now()->toIso8601String(),
            'success' => true,
            'size_bytes' => strlen($bytes),
        ];
    }

    private function assertSafeOfficialUrl(string $url): void
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https') {
            throw new RuntimeException('Solo se permiten fuentes HTTPS.');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('La fuente oficial no tiene host válido.');
        }

        $allowed = array_filter(array_map(
            fn (string $value) => strtolower(trim($value)),
            explode(',', (string) env('FINANCIAL_TARIFF_ALLOWED_DOMAINS', implode(',', [
                'sb.gob.do',
                'popularenlinea.com',
                'banreservas.com',
                'bhd.com.do',
                'qik.com.do',
                'apap.com.do',
            ]))),
        ));

        $allowedHost = false;
        foreach ($allowed as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                $allowedHost = true;
                break;
            }
        }
        if (!$allowedHost) {
            throw new RuntimeException("Dominio no autorizado para tarifarios: {$host}");
        }

        $addresses = gethostbynamel($host) ?: [];
        if ($addresses === []) {
            throw new RuntimeException("No se pudo resolver el dominio oficial: {$host}");
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException("Dominio oficial resuelve a una dirección no permitida: {$host}");
            }
        }
    }
}
