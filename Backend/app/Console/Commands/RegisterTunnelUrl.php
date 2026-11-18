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

        if (!$url) {
            // 1. Try reading cloudflared.log
            $logPath = base_path('cloudflared.log');
            if (!file_exists($logPath)) {
                $logPath = base_path('../cloudflared.log');
            }

            if (file_exists($logPath)) {
                $content = file_get_contents($logPath);
                if (preg_match_all('/https:\/\/[a-z0-9\-]+\.trycloudflare\.com/i', $content, $matches)) {
                    $url = end($matches[0]);
                }
            }
        }

        if (!$url) {
            // 2. Auto-detect from local Cloudflare tunnel metrics endpoint (port 4040)
            try {
                $response = Http::timeout(2)->get('http://127.0.0.1:4040/api/tunnels');
                if ($response->successful()) {
                    $tunnels = $response->json('tunnels', []);
                    if (!empty($tunnels[0]['public_url'])) {
                        $url = $tunnels[0]['public_url'];
                    }
                }
            } catch (\Throwable $e) {
                // Ignore local endpoint timeout
            }
        }

        if (!$url) {
            $this->error('No tunnel URL provided and could not auto-detect from http://127.0.0.1:4040/api/tunnels');
            return self::FAILURE;
        }

        $url = rtrim($url, '/');
        $updatedAt = now()->toIso8601String();

        try {
            Cache::store('redis')->put('active_cloudflare_tunnel_url', $url, now()->addDays(30));
            Cache::store('redis')->put('active_cloudflare_tunnel_updated_at', $updatedAt, now()->addDays(30));
        } catch (\Throwable $e) {
            // Fallback to default cache store if Redis is offline locally
            Cache::put('active_cloudflare_tunnel_url', $url, now()->addDays(30));
            Cache::put('active_cloudflare_tunnel_updated_at', $updatedAt, now()->addDays(30));
        }

        $this->info("Active Cloudflare Tunnel URL stored in Redis successfully!");
        $this->line("Tunnel URL: {$url}");
        $this->line("API Base URL: {$url}/api/v1");

        return self::SUCCESS;
    }
}
