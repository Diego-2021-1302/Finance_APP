<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsRequest;
use App\Models\UserPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $settings = UserPreference::query()->firstOrCreate(['user_id' => $request->user()->id], [
            'currency' => 'DOP', 'timezone' => 'America/Santo_Domingo', 'reminders' => 3, 'week_starts' => 'Monday', 'appearance' => 'system',
        ]);
        return response()->json(['data' => $this->serialize($settings)]);
    }

    public function update(SettingsRequest $request): JsonResponse
    {
        $settings = UserPreference::query()->updateOrCreate(['user_id' => $request->user()->id], $request->validated());
        return response()->json(['data' => $this->serialize($settings)]);
    }

    private function serialize(UserPreference $settings): array
    {
        return ['currency' => $settings->currency, 'timezone' => $settings->timezone, 'reminders' => $settings->reminders, 'week_starts' => $settings->week_starts, 'appearance' => $settings->appearance];
    }
}
