<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Commitment;
use App\Models\Debt;
use App\Models\FinancialTransaction;
use App\Models\Income;
use App\Services\GeminiFinanceInsights;
use App\Services\GeminiNotConfiguredException;
use App\Services\GeminiUpstreamException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceInsightsController extends Controller
{
    public function recommendations(Request $request, GeminiFinanceInsights $gemini): JsonResponse
    {
        $request->validate(['consent' => ['required', 'accepted']]);
        $userId = $request->user()->id;
        $from = now()->subMonths(6)->startOfMonth();

        $data = [
            'period' => ['from' => $from->toDateString(), 'to' => now()->toDateString()],
            'currency' => 'DOP',
            'income_forecasts' => Income::query()
                ->where('user_id', $userId)
                ->whereDate('expected_date', '>=', $from)
                ->orderBy('expected_date')
                ->limit(200)
                ->get(['source', 'amount', 'expected_date'])
                ->map(fn (Income $income) => [
                    'source' => $income->source,
                    'amount' => $income->amount,
                    'expected_date' => $income->expected_date->toDateString(),
                ])->values()->all(),
            'account_movements' => FinancialTransaction::query()
                ->where('user_id', $userId)
                ->where('occurred_at', '>=', $from)
                ->orderByDesc('occurred_at')
                ->limit(300)
                ->get(['description', 'amount', 'occurred_at', 'kind'])
                ->map(fn (FinancialTransaction $transaction) => [
                    'description' => $transaction->description,
                    'amount' => $transaction->amount,
                    'date' => $transaction->occurred_at->toDateString(),
                    'kind' => $transaction->kind,
                ])->values()->all(),
            'current_debts' => Debt::query()
                ->where('user_id', $userId)
                ->orderBy('due_date')
                ->get(['kind', 'outstanding_amount', 'minimum_payment', 'minimum_payment_confirmed', 'due_date'])
                ->map(fn (Debt $debt) => [
                    'type' => $debt->kind,
                    'outstanding' => $debt->outstanding_amount,
                    'minimum_payment' => $debt->minimum_payment_confirmed ? $debt->minimum_payment : null,
                    'minimum_payment_confirmed' => $debt->minimum_payment_confirmed,
                    'due_date' => $debt->due_date?->toDateString(),
                ])->values()->all(),
            'commitments' => Commitment::query()
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->orderBy('next_due_date')
                ->limit(100)
                ->get(['name', 'type', 'amount', 'frequency', 'next_due_date', 'current_balance', 'interest_rate', 'category'])
                ->map(fn (Commitment $commitment) => [
                    'description' => $commitment->name,
                    'type' => $commitment->type,
                    'amount' => $commitment->amount,
                    'frequency' => $commitment->frequency,
                    'next_due_date' => $commitment->next_due_date?->toDateString(),
                    'current_balance' => $commitment->current_balance,
                    'interest_rate' => $commitment->interest_rate,
                    'category' => $commitment->category,
                ])->values()->all(),
        ];

        try {
            return response()->json(['data' => $gemini->generate($data)]);
        } catch (GeminiNotConfiguredException $exception) {
            return response()->json([
                'error' => ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'Gemini no está configurado. Añade GEMINI_API_KEY al archivo Backend/.env.', 'details' => null],
            ], 503);
        } catch (GeminiUpstreamException $exception) {
            return response()->json([
                'error' => ['code' => 'UPSTREAM_ERROR', 'message' => $exception->getMessage(), 'details' => null],
            ], 502);
        }
    }
}
