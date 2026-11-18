<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CommitmentOccurrence;
use App\Models\FinancialTransaction;
use App\Models\Income;
use App\Models\Payment;
use App\Services\MoneyFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function summary(Request $request, MoneyFormatter $money): JsonResponse
    {
        $user = $request->user();
        $cacheKey = sprintf('user_dashboard_summary_%d', $user->id);

        $data = Cache::remember($cacheKey, 10, function () use ($user, $money) {
            $confirmed = (string) FinancialTransaction::query()->where('user_id', $user->id)->sum('amount');
            $totalDebt = (string) \App\Models\Debt::query()->where('user_id', $user->id)->sum('outstanding_amount');
            $startOfMonth = now()->startOfMonth();
            $endOfMonth = now()->endOfMonth();

            $expected = (string) Income::query()
                ->where('user_id', $user->id)
                ->whereDate('expected_date', '>=', $startOfMonth)
                ->whereDate('expected_date', '<=', $endOfMonth)
                ->sum('amount');

            $received = (string) Payment::query()
                ->where('user_id', $user->id)
                ->whereNotNull('income_id')
                ->whereNull('reversed_at')
                ->whereBetween('paid_at', [$startOfMonth, $endOfMonth])
                ->sum('amount');

            $occurrences = CommitmentOccurrence::query()
                ->where('user_id', $user->id)
                ->whereBetween('due_date', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
                ->where('status', '!=', 'paid')
                ->with('commitment')
                ->orderBy('due_date')
                ->limit(5)
                ->get();

            $dueSoon = '0.00';
            foreach ($occurrences as $occurrence) {
                $dueSoon = bcadd($dueSoon, (string) $occurrence->amount_due, 2);
            }

            return [
                'balances' => [
                    'confirmed' => $money->dop($confirmed),
                    'totalDebt' => $money->dop($totalDebt),
                    'accountCount' => Account::query()->where('user_id', $user->id)->where('is_active', true)->count(),
                ],
                'income' => [
                    'expected' => $money->dop($expected),
                    'received' => $money->dop($received),
                ],
                'commitments' => [
                    'dueSoon' => $money->dop($dueSoon),
                    'dueSoonLabel' => $occurrences->first()?->commitment?->name ?? 'Sin compromisos próximos',
                ],
                'upcoming' => $occurrences->map(fn (CommitmentOccurrence $item) => [
                    'label' => $item->commitment?->name ?? 'Compromiso',
                    'dueDate' => $item->due_date->format('M j'),
                    'amount' => $money->dop((string) $item->amount_due),
                    'kind' => 'Compromiso',
                ])->values(),
            ];
        });

        return response()->json($data);
    }
}
