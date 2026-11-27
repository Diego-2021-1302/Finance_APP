<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserPreference;
use App\Services\FinancialPlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialPlanController extends Controller
{
    public function index(Request $request, FinancialPlanningService $planning): JsonResponse
    {
        $validated = $request->validate(['days' => ['sometimes', 'integer', 'min:1', 'max:90']]);
        $payFrequency = UserPreference::query()->where('user_id', $request->user()->id)->value('pay_frequency') ?? 'monthly';
        $defaultDays = match ($payFrequency) {
            'weekly' => 7,
            'biweekly' => 15,
            default => 30,
        };
        $days = (int) ($validated['days'] ?? $defaultDays);
        $plan = $planning->calculate($request->user()->id, $days);
        return response()->json(['data' => $plan]);
    }
}
