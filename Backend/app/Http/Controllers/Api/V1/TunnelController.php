<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TunnelController extends Controller
{
    public function show(): JsonResponse
    {
        $url = Cache::store('redis')->get('finance_app_tunnel_url')
            ?? Cache::get('finance_app_tunnel_url')
            ?? config('app.url');

        return response()->json([
            'tunnel_url' => $url,
            'api_base_url' => rtrim($url, '/').'/api/v1',
            'updated_at' => Cache::store('redis')->get('finance_app_tunnel_updated_at') ?? now()->toIso8601String(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $secret = env('TUNNEL_REGISTER_SECRET') ?? config('app.tunnel_register_secret');
        if ($secret) {
            $provided = $request->header('X-Tunnel-Secret')
                ?? $request->header('X-Register-Token')
                ?? $request->input('secret')
                ?? $request->input('token');

            if ($provided !== $secret) {
                return response()->json([
                    'error' => [
                        'code' => 'UNAUTHORIZED',
                        'message' => 'No autorizado para registrar la dirección del túnel.',
                    ],
                ], 401);
            }
        }

        $validated = $request->validate([
            'tunnel_url' => ['required', 'url', 'max:255', 'regex:/^https?:\/\/[a-zA-Z0-9\-\.]+(:\d+)?(\/.*)?$/'],
        ]);

        $url = rtrim($validated['tunnel_url'], '/');
        $updatedAt = now()->toIso8601String();

        try {
            Cache::store('redis')->put('finance_app_tunnel_url', $url, now()->addDays(30));
            Cache::store('redis')->put('finance_app_tunnel_updated_at', $updatedAt, now()->addDays(30));
        } catch (\Throwable $e) {
            Cache::put('finance_app_tunnel_url', $url, now()->addDays(30));
            Cache::put('finance_app_tunnel_updated_at', $updatedAt, now()->addDays(30));
        }

        return response()->json([
            'data' => [
                'tunnel_url' => $url,
                'api_base_url' => $url.'/api/v1',
                'updated_at' => $updatedAt,
                'stored_in' => 'redis',
            ],
        ]);
    }
}
