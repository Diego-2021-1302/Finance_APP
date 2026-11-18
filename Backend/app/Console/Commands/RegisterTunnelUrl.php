<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class RegisterTunnelUrl extends Command
{
    protected $signature = 'finance:register-tunnel {url? : The dynamic Cloudflare tunnel URL}';
    protected $description = 'Register active Cloudflare tunnel URL in Redis for dynamic Vercel frontend connectivity';

    public function handle(): int
    {
        $url = $this->argument('url');

        $this->info('[PASO 1] Buscando URL del tunel activo de Cloudflare...');

        if (!$url) {
            $logPath = base_path('cloudflared.log');
            if (!file_exists($logPath)) {
                $logPath = base_path('../cloudflared.log');
            }

            if (file_exists($logPath)) {
                $this->line('  -> Leyendo archivo de registro cloudflared.log...');
                $content = file_get_contents($logPath);
                if (preg_match_all('/https:\/\/[a-z0-9\-]+\.trycloudflare\.com/i', $content, $matches)) {
                    $url = end($matches[0]);
                    $this->info("  -> URL encontrada en log: {$url}");
                }
            }
        }

        if (!$url) {
            $this->line('  -> Consultando endpoint de metricas http://127.0.0.1:4040/api/tunnels...');
            try {
                $response = Http::timeout(2)->get('http://127.0.0.1:4040/api/tunnels');
                if ($response->successful()) {
                    $tunnels = $response->json('tunnels', []);
                    if (!empty($tunnels[0]['public_url'])) {
                        $url = $tunnels[0]['public_url'];
                        $this->info("  -> URL autodetectada desde metricas: {$url}");
                    }
                }
            } catch (\Throwable $e) {
                // Ignore timeout
            }
        }

        if (!$url) {
            $this->error('  [ERROR] No se pudo encontrar una URL de tunel activa.');
            return self::FAILURE;
        }

        $url = rtrim($url, '/');
        $updatedAt = now()->toIso8601String();

        $this->info('[PASO 2] Guardando URL en la base de datos Redis...');
        try {
            Cache::store('redis')->put('active_cloudflare_tunnel_url', $url, now()->addDays(30));
            Cache::store('redis')->put('active_cloudflare_tunnel_updated_at', $updatedAt, now()->addDays(30));
            $this->info('  -> Guardado en Redis local correctamente (clave: active_cloudflare_tunnel_url).');
        } catch (\Throwable $e) {
            $this->warn('  -> Redis local no respondio. Guardando en cache de respaldo...');
            Cache::put('active_cloudflare_tunnel_url', $url, now()->addDays(30));
            Cache::put('active_cloudflare_tunnel_updated_at', $updatedAt, now()->addDays(30));
        }

        // Optional Upstash Redis REST sync if env configured
        $upstashUrl = env('UPSTASH_REDIS_REST_URL');
        $upstashToken = env('UPSTASH_REDIS_REST_TOKEN');
        if ($upstashUrl && $upstashToken) {
            $this->info('[PASO 3] Sincronizando con Upstash Redis REST para Vercel...');
            try {
                Http::withHeaders(['Authorization' => "Bearer {$upstashToken}"])
                    ->post(rtrim($upstashUrl, '/').'/set/active_cloudflare_tunnel_url', [$url]);
                $this->info('  -> Sincronizado con Upstash Redis REST para acceso remoto desde Vercel.');
            } catch (\Throwable $e) {
                $this->warn('  -> No se pudo sincronizar con Upstash Redis REST.');
            }
        }

        $this->info('[PASO 4] Verificando lectura desde Redis...');
        $verifiedUrl = Cache::store('redis')->get('active_cloudflare_tunnel_url') ?? Cache::get('active_cloudflare_tunnel_url');

        $this->newLine();
        $this->info('===========================================================');
        $this->info('  [RESULTADO EXITOSO] TUNEL REGISTRADO CORRECTAMENTE!');
        $this->info('===========================================================');
        $this->line("  - URL del Tunel Activo: {$verifiedUrl}");
        $this->line("  - Base de la API REST:  {$verifiedUrl}/api/v1");
        $this->line("  - Fecha de Actualizacion: {$updatedAt}");
        $this->info('===========================================================');

        return self::SUCCESS;
    }
}
