<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CommitmentOccurrence;
use App\Models\FinancialTransaction;
use App\Models\Income;
use App\Models\Payment;
use App\Models\Reserve;
use App\Models\Budget;
use App\Models\Expense;
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
            $confirmed = (string) (FinancialTransaction::query()
                ->where('user_id', $user->id)
                ->whereHas('account', fn ($query) => $query->where('type', '!=', 'credit_card'))
                ->sum('amount') ?: '0.00');
            $totalDebt = (string) (\App\Models\Debt::query()->where('user_id', $user->id)->sum('outstanding_amount') ?: '0.00');
            $startOfMonth = now()->startOfMonth();
            $endOfMonth = now()->endOfMonth();

            $reservedAmount = (string) (Reserve::query()->where('user_id', $user->id)->sum('current_amount') ?: '0.00');
            $availableUncommitted = bcsub($confirmed, $reservedAmount, 2);

            $currentYearMonth = now()->format('Y-m');
            $budgets = Budget::query()
                ->where('user_id', $user->id)
                ->where('year_month', $currentYearMonth)
                ->get()
                ->map(function ($budget) use ($user, $startOfMonth, $endOfMonth, $money) {
                    $spent = (string) (Expense::query()
                        ->where('user_id', $user->id)
                        ->where('category', $budget->category)
                        ->whereBetween('occurred_at', [$startOfMonth, $endOfMonth])
                        ->sum('amount') ?: '0.00');
                    $remaining = bcsub((string) $budget->monthly_limit, $spent, 2);
                    $percentage = bccomp((string) $budget->monthly_limit, '0.00', 2) > 0
                        ? bcmul(bcdiv($spent, (string) $budget->monthly_limit, 4), '100', 1)
                        : '0.0';

                    return [
                        'category' => $budget->category,
                        'limit' => $money->dop((string) $budget->monthly_limit),
                        'spent' => $money->dop($spent),
                        'remaining' => $money->dop($remaining),
                        'percentage' => (float) $percentage,
                    ];
                });

            $expected = (string) (Income::query()
                ->where('user_id', $user->id)
                ->whereDate('expected_date', '>=', $startOfMonth)
                ->whereDate('expected_date', '<=', $endOfMonth)
                ->sum('amount') ?: '0.00');

            $received = (string) (Payment::query()
                ->where('user_id', $user->id)
                ->whereNotNull('income_id')
                ->whereNull('reversed_at')
                ->whereBetween('paid_at', [$startOfMonth, $endOfMonth])
                ->sum('amount') ?: '0.00');

            $occurrences = CommitmentOccurrence::query()
                ->where('user_id', $user->id)
                ->whereBetween('due_date', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
                ->where('status', '!=', 'paid')
                ->with(['commitment', 'payments'])
                ->orderBy('due_date')
                ->get();

            $dueSoon = '0.00';
            foreach ($occurrences as $occurrence) {
                $paid = '0.00';
                foreach ($occurrence->payments->whereNull('reversed_at') as $payment) {
                    $paid = bcadd($paid, (string) $payment->amount, 2);
                }

                $remaining = bcsub((string) $occurrence->amount_due, $paid, 2);
                if (bccomp($remaining, '0.00', 2) > 0) {
                    $dueSoon = bcadd($dueSoon, $remaining, 2);
                }
            }

            return [
                'balances' => [
                    'real' => $money->dop($confirmed),
                    'confirmed' => $money->dop($confirmed),
                    'reserved' => $money->dop($reservedAmount),
                    'availableUncommitted' => $money->dop($availableUncommitted),
                    'totalDebt' => $money->dop($totalDebt),
                    'accountCount' => Account::query()
                        ->where('user_id', $user->id)
                        ->where('type', '!=', 'credit_card')
                        ->count(),
                ],
                'budgets' => $budgets,
                'income' => [
                    'expected' => $money->dop($expected),
                    'received' => $money->dop($received),
                ],
                'commitments' => [
                    'dueSoon' => $money->dop($dueSoon),
                    'dueSoonLabel' => $occurrences->first()?->commitment?->name ?? 'Sin compromisos próximos',
                ],
                'upcoming' => $occurrences
                    ->map(function (CommitmentOccurrence $item) use ($money) {
                        $paid = '0.00';
                        foreach ($item->payments->whereNull('reversed_at') as $payment) {
                            $paid = bcadd($paid, (string) $payment->amount, 2);
                        }
                        $remaining = bcsub((string) $item->amount_due, $paid, 2);

                        return [
                            'label' => $item->commitment?->name ?? 'Compromiso',
                            'dueDate' => $item->due_date->format('M j'),
                            'amount' => $money->dop($remaining),
                            'kind' => 'Compromiso',
                            'remaining' => $remaining,
                        ];
                    })
                    ->filter(fn (array $item) => bccomp($item['remaining'], '0.00', 2) > 0)
                    ->take(5)
                    ->map(fn (array $item) => array_diff_key($item, ['remaining' => true]))
                    ->values(),
            ];
        });

        return response()->json($data);
    }
}
