<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CommitmentOccurrence;
use App\Models\FinancialTransaction;
use App\Models\Income;
use App\Models\Payment;
use App\Models\Reserve;
use App\Models\MonthlyDebtHistory;
use App\Models\Budget;
use App\Models\Expense;
use App\Services\MoneyFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function debtHistory(Request $request): JsonResponse
    {
        $user = $request->user();
        $history = MonthlyDebtHistory::query()
            ->where('user_id', $user->id)
            ->orderBy('month')
            ->get()
            ->map(fn (MonthlyDebtHistory $item) => [
                'month' => $item->month,
                'label' => \Illuminate\Support\Carbon::createFromFormat('Y-m', $item->month)
                    ->locale('es')
                    ->translatedFormat('M Y'),
                'total_debt' => (string) $item->total_debt,
                'card_debt' => (string) $item->card_debt,
                'loan_debt' => (string) $item->loan_debt,
                'other_debt' => (string) $item->other_debt,
                'captured_at' => $item->captured_at->toDateString(),
                'source' => $item->source,
            ]);

        $currentMonth = now()->format('Y-m');
        $debts = \App\Models\Debt::query()->where('user_id', $user->id)->get();
        $card = $debts->where('kind', 'credit_card')->sum(fn ($debt) => (float) $debt->outstanding_amount);
        $loan = $debts->where('kind', 'loan')->sum(fn ($debt) => (float) $debt->outstanding_amount);
        $other = $debts->whereNotIn('kind', ['credit_card', 'loan'])->sum(fn ($debt) => (float) $debt->outstanding_amount);

        $history = $history
            ->reject(fn (array $item) => $item['month'] === $currentMonth)
            ->values();
        $history->push([
            'month' => $currentMonth,
            'label' => now()->locale('es')->translatedFormat('M Y'),
            'total_debt' => number_format($card + $loan + $other, 2, '.', ''),
            'card_debt' => number_format($card, 2, '.', ''),
            'loan_debt' => number_format($loan, 2, '.', ''),
            'other_debt' => number_format($other, 2, '.', ''),
            'captured_at' => now()->toDateString(),
            'source' => 'current_estimate',
        ]);

        $series = $history->sortBy('month')->take(-12)->values();
        return response()->json(['data' => $series]);
    }

    public function summary(Request $request, MoneyFormatter $money): JsonResponse
    {
        $user = $request->user();

        $confirmed = (string) (FinancialTransaction::query()
            ->where('user_id', $user->id)
            ->whereHas('account', fn ($query) => $query->where('type', '!=', 'credit_card'))
            ->sum('amount') ?: '0.00');
        $totalDebt = (string) (\App\Models\Debt::query()->where('user_id', $user->id)->sum('outstanding_amount') ?: '0.00');
        $cards = \App\Models\Debt::query()
            ->where('user_id', $user->id)
            ->where('kind', 'credit_card')
            ->orderBy('name')
            ->get()
            ->map(function (\App\Models\Debt $debt) use ($money) {
                $engine = app(\App\Services\CreditCardAuthorizationEngine::class);
                $metrics = $engine->getCapacityMetrics($debt);
                return [
                    'id' => $debt->id,
                    'name' => $debt->name,
                    'balance' => $money->dop((string) $debt->outstanding_amount),
                    'approved_credit_limit' => $money->dop($metrics['approved_credit_limit']),
                    'authorized_overdraft_limit' => $money->dop($metrics['authorized_overdraft_limit']),
                    'remaining_total_capacity' => $money->dop($metrics['remaining_total_capacity']),
                    'remaining_overdraft' => $money->dop($metrics['remaining_overdraft']),
                    'limit_status' => $debt->limit_status ?? 'unknown',
                    'classification' => 'confirmed',
                ];
            })
            ->values();
        $debtBreakdown = \App\Models\Debt::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get()
            ->map(fn (\App\Models\Debt $debt) => [
                'id' => $debt->id,
                'name' => $debt->name,
                'kind' => $debt->kind,
                'outstanding' => $money->dop((string) $debt->outstanding_amount),
                'classification' => 'confirmed',
            ])
            ->values();
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

        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $monthOccurrences = CommitmentOccurrence::query()
            ->where('user_id', $user->id)
            ->whereBetween('due_date', [$monthStart, $monthEnd])
            ->where('status', '!=', 'paid')
            ->with(['commitment', 'payments'])
            ->orderBy('due_date')
            ->get();

        $commitmentGroups = [];

        foreach ($monthOccurrences as $occurrence) {
            $month = $occurrence->due_date->format('Y-m');
            $paid = '0.00';
            foreach ($occurrence->payments->whereNull('reversed_at') as $payment) {
                $paid = bcadd($paid, (string) $payment->amount, 2);
            }
            $remaining = bcsub((string) $occurrence->amount_due, $paid, 2);
            if (bccomp($remaining, '0.00', 2) > 0) {
                $commitmentGroups[$month]['total'] = bcadd($commitmentGroups[$month]['total'] ?? '0.00', $remaining, 2);
                $commitmentGroups[$month]['items'][] = [
                    'source' => 'compromiso',
                    'name' => $occurrence->commitment?->name ?? 'Compromiso',
                    'date' => $occurrence->due_date->toDateString(),
                    'amount' => $remaining,
                ];
            }
        }

        $debtItems = \App\Models\Debt::query()
            ->where('user_id', $user->id)
            ->where('outstanding_amount', '>', 0)
            ->orderBy('due_date')
            ->get();

        foreach ($debtItems as $debt) {
            if (!$debt->due_date) continue;
            if (bccomp((string) $debt->outstanding_amount, '0.00', 2) <= 0) continue;

            $today = now()->startOfDay();

            $amount = $debt->kind === 'credit_card'
                ? (string) ($debt->statement_balance ?? '0.00')
                : ($debt->kind === 'loan'
                    ? (string) ($debt->minimum_payment ?? '0.00')
                    : '0.00');

            if (bccomp($amount, '0.00', 2) <= 0) continue;

            $cursor = $debt->due_date->copy()->startOfDay();
            if ($cursor->lt($today) && $debt->kind === 'credit_card') {
                $cursor = $today->copy()->startOfMonth()->day(min(1, $today->daysInMonth));
            }
            $limit = $debt->maturity_date
                ? \Illuminate\Support\Carbon::parse($debt->maturity_date)->startOfDay()
                : now()->copy()->startOfDay()->addMonths(12);
            $steps = 0;

            while ($cursor->lte($limit) && $steps < 120) {
                $month = $cursor->format('Y-m');
                $commitmentGroups[$month]['total'] = bcadd($commitmentGroups[$month]['total'] ?? '0.00', $amount, 2);
                $commitmentGroups[$month]['items'][] = [
                    'source' => $debt->kind === 'credit_card' ? 'tarjeta' : 'prestamo',
                    'name' => $debt->name,
                    'date' => $cursor->toDateString(),
                    'amount' => $amount,
                ];

                if ($debt->kind === 'credit_card') {
                    break;
                } elseif ($debt->kind === 'loan' && $debt->amortization_type === 'biweekly') {
                    $cursor = $cursor->copy()->addDays(15);
                } elseif ($debt->kind === 'loan') {
                    $cursor = \App\Services\LoanPaymentSchedule::addMonthKeepingDay($cursor);
                } else {
                    break;
                }
                $steps++;
            }
        }

        ksort($commitmentGroups);
        $formattedGroups = collect($commitmentGroups)->map(function (array $group, string $month) use ($money) {
            $start = \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            return [
                'month' => $month,
                'label' => $start->locale('es')->translatedFormat('F Y'),
                'total' => $money->dop($group['total']),
                'items' => collect($group['items'])->map(function (array $item) use ($money) {
                    $item['amount'] = $money->dop($item['amount']);
                    return $item;
                })->values(),
            ];
        })->values();

        $data = [
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
                'cards' => $cards,
                'debtBreakdown' => $debtBreakdown,
                'reservesBreakdown' => Reserve::query()
                    ->where('user_id', $user->id)
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Reserve $reserve) => [
                        'id' => $reserve->id,
                        'name' => $reserve->name,
                        'currentAmount' => $money->dop((string) $reserve->current_amount),
                        'targetAmount' => $money->dop((string) $reserve->target_amount),
                        'classification' => 'confirmed',
                    ])
                    ->values(),
            ],
            'budgets' => $budgets,
            'income' => [
                'expected' => $money->dop($expected),
                'received' => $money->dop($received),
            ],
            'commitmentGroups' => $formattedGroups,
            'activeCommitmentsCount' => \App\Models\Commitment::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->count(),
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

        return response()->json($data);
    }
}
