<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\Reserve;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetReserveController extends Controller
{
    public function indexBudgets(Request $request): JsonResponse
    {
        $yearMonth = $request->query('year_month', now()->format('Y-m'));
        $budgets = Budget::query()
            ->where('user_id', $request->user()->id)
            ->where('year_month', $yearMonth)
            ->get();

        return response()->json(['data' => $budgets]);
    }

    public function storeBudget(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', 'string', 'max:100'],
            'monthly_limit' => ['required', 'numeric', 'min:0.01'],
            'year_month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $budget = Budget::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'category' => $validated['category'],
                'year_month' => $validated['year_month'],
            ],
            [
                'monthly_limit' => $validated['monthly_limit'],
            ]
        );

        return response()->json(['data' => $budget], 201);
    }

    public function indexReserves(Request $request): JsonResponse
    {
        $reserves = Reserve::query()
            ->where('user_id', $request->user()->id)
            ->get();

        return response()->json(['data' => $reserves]);
    }

    public function storeReserve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'target_amount' => ['required', 'numeric', 'min:0.01'],
            'current_amount' => ['sometimes', 'numeric', 'min:0'],
            'commitment_id' => [
                'nullable',
                'exists:commitments,id',
                function ($attribute, $value, $fail) use ($request) {
                    if ($value && !\App\Models\Commitment::query()->where('user_id', $request->user()->id)->whereKey($value)->exists()) {
                        $fail('El compromiso seleccionado no pertenece al usuario.');
                    }
                },
            ],
        ]);

        $reserve = Reserve::query()->create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'target_amount' => $validated['target_amount'],
            'current_amount' => $validated['current_amount'] ?? '0.00',
            'commitment_id' => $validated['commitment_id'] ?? null,
        ]);

        return response()->json(['data' => $reserve], 201);
    }
}
