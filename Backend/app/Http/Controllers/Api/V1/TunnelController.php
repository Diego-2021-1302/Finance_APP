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
        $validated = $request->validate([
            'tunnel_url' => ['required', 'url'],
        ]);

        $url = rtrim($validated['tunnel_url'], '/');
        $updatedAt = now()->toIso8601String();

        Cache::store('redis')->put('finance_app_tunnel_url', $url, now()->addDays(30));
        Cache::store('redis')->put('finance_app_tunnel_updated_at', $updatedAt, now()->addDays(30));

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
