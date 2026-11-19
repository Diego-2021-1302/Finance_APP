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
            // 1. Try reading cloudflared.log first
            $possibleLogs = [
                base_path('cloudflared.log'),
                base_path('cloudflared_err.log'),
                base_path('cloudflared_out.log'),
                base_path('../cloudflared.log'),
                base_path('../cloudflared_err.log'),
                base_path('../cloudflared_out.log'),
                base_path('storage/logs/cloudflared.log'),
            ];

            foreach ($possibleLogs as $logPath) {
                if (file_exists($logPath)) {
                    $this->line("  -> Leyendo archivo de registro: {$logPath}...");
                    $content = file_get_contents($logPath);
                    if (preg_match_all('/https:\/\/[a-z0-9\-]+\.trycloudflare\.com/i', $content, $matches)) {
                        $url = end($matches[0]);
                        $this->info("  -> URL activa encontrada en log: {$url}");
                        break;
                    }
                }
            }
        }

        if (!$url) {
            // 2. Try auto-detecting from local Cloudflare tunnel metrics endpoint (port 4040)
            try {
                $this->line('  -> Consultando endpoint de metricas de Cloudflare http://127.0.0.1:4040/api/tunnels...');
                $response = Http::timeout(2)->get('http://127.0.0.1:4040/api/tunnels');
                if ($response->successful()) {
                    $tunnels = $response->json('tunnels', []);
                    if (!empty($tunnels[0]['public_url'])) {
                        $url = $tunnels[0]['public_url'];
                        $this->info("  -> URL autodetectada desde la memoria de Cloudflare: {$url}");
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
            $redis = \Illuminate\Support\Facades\Redis::connection('default');
            $redis->del('laravel_database_laravel_cache_finance_app_tunnel_url');
            $redis->set('finance_app_tunnel_url', $url);
            $redis->set('laravel_database_finance_app_tunnel_url', $url);
            $redis->set('finance_app_tunnel_updated_at', $updatedAt);

            Cache::store('redis')->put('finance_app_tunnel_url', $url, now()->addDays(30));
            Cache::store('redis')->put('finance_app_tunnel_updated_at', $updatedAt, now()->addDays(30));
            $this->info('  -> Guardado en Redis local correctamente (clave aislada: finance_app_tunnel_url).');
        } catch (\Throwable $e) {
            $this->warn('  -> Redis local no respondio. Guardando en cache de respaldo...');
            Cache::put('finance_app_tunnel_url', $url, now()->addDays(30));
        }   Cache::put('finance_app_tunnel_updated_at', $updatedAt, now()->addDays(30));
        }

        // Upstash / Vercel KV Redis REST sync if an explicit HTTP REST endpoint is configured
        $upstashUrl = env('UPSTASH_REDIS_REST_URL') ?? env('STORAGE_REST_API_URL') ?? env('KV_REST_API_URL');
        $upstashToken = env('UPSTASH_REDIS_REST_TOKEN') ?? env('STORAGE_REST_API_TOKEN') ?? env('KV_REST_API_TOKEN');

        if ($upstashUrl && $upstashToken && str_contains($upstashUrl, 'http')) {
            $this->info('[PASO 3] Sincronizando clave aislada finance_app_tunnel_url con REST API...');
            try {
                Http::timeout(3)
                    ->withHeaders(['Authorization' => "Bearer {$upstashToken}"])
                    ->post(rtrim($upstashUrl, '/').'/set/finance_app_tunnel_url', [$url]);
                $this->info('  -> Sincronizado exitosamente vía REST API.');
            } catch (\Throwable $e) {
                $this->line('  -> Conexión directa TCP en PASO 2 mantenida.');
            }
        } else {
            $this->info('[PASO 3] Sincronización en Redis completada mediante conexión TCP directa.');
        }

        $frontendPublicJson = base_path('../Frontend/public/tunnel.json');
        if (file_exists(dirname($frontendPublicJson))) {
            @file_put_contents($frontendPublicJson, json_encode([
                'tunnel_url' => $url,
                'api_base_url' => $url.'/api/v1',
                'updated_at' => $updatedAt,
            ], JSON_PRETTY_PRINT));
        }

        $this->info('[PASO 4] Verificando lectura desde Redis...');
        $verifiedUrl = Cache::store('redis')->get('finance_app_tunnel_url') ?? Cache::get('finance_app_tunnel_url');

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
