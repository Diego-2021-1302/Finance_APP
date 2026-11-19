<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CommitmentOccurrence;
use App\Models\Commitment;
use App\Models\Debt;
use App\Models\DebtBalanceMovement;
use App\Models\Income;
use App\Models\Payment;
use App\Models\FinancialTransaction;
use App\Services\CommitmentRecurrence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function cashFlow(Request $request, CommitmentRecurrence $recurrence): JsonResponse
    {
        $range = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'group_by' => ['sometimes', 'in:day,week,month,category']]);
        $userId = $request->user()->id;
        $fromDate = Carbon::parse($range['from']);
        $toDate = Carbon::parse($range['to']);
        $incomeForecast = (string) Income::query()->where('user_id', $userId)->whereBetween('expected_date', [$range['from'], $range['to']])->sum('amount');
        $commitmentForecastByMonth = $this->commitmentForecastByMonth($userId, $fromDate, $toDate, $recurrence);
        $commitmentForecast = '0.00';
        foreach ($commitmentForecastByMonth as $amount) $commitmentForecast = bcadd($commitmentForecast, $amount, 2);
        $commitmentForecast = bcsub('0.00', $commitmentForecast, 2);
        $debtForecast = bcsub('0.00', (string) Debt::query()->where('user_id', $userId)->where('minimum_payment_confirmed', true)->whereBetween('due_date', [$range['from'], $range['to']])->sum('minimum_payment'), 2);
        $incomeActual = (string) Payment::query()->where('user_id', $userId)->whereNotNull('income_id')->whereNull('reversed_at')->whereBetween('paid_at', [$fromDate->startOfDay(), $toDate->endOfDay()])->sum('amount');
        $commitmentActual = bcsub('0.00', (string) Payment::query()->where('user_id', $userId)->whereNotNull('commitment_occurrence_id')->whereNull('reversed_at')->whereBetween('paid_at', [$fromDate->startOfDay(), $toDate->endOfDay()])->sum('amount'), 2);
        $debtActual = (string) DebtBalanceMovement::query()->where('user_id', $userId)->where('kind', 'payment')->whereNull('payment_id')->whereBetween('occurred_at', [$fromDate->startOfDay(), $toDate->endOfDay()])->sum('delta');

        $categories = [
            $this->category('Income', $incomeForecast, $incomeActual),
            $this->category('Commitments', $commitmentForecast, $commitmentActual),
            $this->category('Debt payments', $debtForecast, $debtActual),
        ];
        $forecastTotal = bcadd(bcadd($incomeForecast, $commitmentForecast, 2), $debtForecast, 2);
        $actualTotal = bcadd(bcadd($incomeActual, $commitmentActual, 2), $debtActual, 2);
        $series = [];
        $cursor = Carbon::parse($range['from'])->startOfMonth();
        $lastMonth = Carbon::parse($range['to'])->startOfMonth();
        while ($cursor <= $lastMonth) {
            $monthStart = $cursor->max(Carbon::parse($range['from']));
            $monthEnd = $cursor->copy()->endOfMonth()->min(Carbon::parse($range['to']));
            $forecastIncome = (string) Income::query()->where('user_id', $userId)->whereBetween('expected_date', [$monthStart->toDateString(), $monthEnd->toDateString()])->sum('amount');
            $forecastCommitments = bcsub('0.00', $commitmentForecastByMonth[$cursor->format('Y-m')] ?? '0.00', 2);
            $forecastDebts = bcsub('0.00', (string) Debt::query()->where('user_id', $userId)->where('minimum_payment_confirmed', true)->whereBetween('due_date', [$monthStart->toDateString(), $monthEnd->toDateString()])->sum('minimum_payment'), 2);
            $actualIncome = (string) Payment::query()->where('user_id', $userId)->whereNotNull('income_id')->whereNull('reversed_at')->whereBetween('paid_at', [$monthStart->startOfDay(), $monthEnd->endOfDay()])->sum('amount');
            $actualCommitments = bcsub('0.00', (string) Payment::query()->where('user_id', $userId)->whereNotNull('commitment_occurrence_id')->whereNull('reversed_at')->whereBetween('paid_at', [$monthStart->startOfDay(), $monthEnd->endOfDay()])->sum('amount'), 2);
            $actualDebtPayments = (string) DebtBalanceMovement::query()->where('user_id', $userId)->where('kind', 'payment')->whereNull('payment_id')->whereBetween('occurred_at', [$monthStart->startOfDay(), $monthEnd->endOfDay()])->sum('delta');
            $series[] = [
                'month' => $cursor->format('M'),
                'forecastMinor' => (int) bcmul(bcadd(bcadd($forecastIncome, $forecastCommitments, 2), $forecastDebts, 2), '100', 0),
                'actualMinor' => (int) bcmul(bcadd(bcadd($actualIncome, $actualCommitments, 2), $actualDebtPayments, 2), '100', 0),
            ];
            $cursor->addMonth();
        }
        return response()->json(['totals' => ['forecast' => $forecastTotal, 'actual' => $actualTotal, 'variance' => bcsub($actualTotal, $forecastTotal, 2)], 'series' => $series, 'categories' => $categories]);
    }

    public function projection(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $initialBalance = (string) FinancialTransaction::query()
            ->where('user_id', $userId)
            ->whereHas('account', fn ($query) => $query->where('type', '!=', 'credit_card'))
            ->sum('amount');

        $from = now()->startOfDay();
        $to = now()->addDays(30)->endOfDay();

        $incomes = Income::query()
            ->where('user_id', $userId)
            ->whereBetween('expected_date', [$from->toDateString(), $to->toDateString()])
            ->get(['expected_date', 'amount'])
            ->groupBy(fn ($item) => $item->expected_date->toDateString());

        $occurrences = CommitmentOccurrence::query()
            ->where('user_id', $userId)
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->where('status', '!=', 'paid')
            ->get(['due_date', 'amount_due'])
            ->groupBy(fn ($item) => $item->due_date->toDateString());

        $cursor = $from->copy();
        $runningBalance = $initialBalance;
        $dailyTimeline = [];
        $deficitRiskDate = null;

        while ($cursor <= $to) {
            $dateStr = $cursor->toDateString();
            $dayIncome = '0.00';
            if (isset($incomes[$dateStr])) {
                foreach ($incomes[$dateStr] as $inc) {
                    $dayIncome = bcadd($dayIncome, (string) $inc->amount, 2);
                    $runningBalance = bcadd($runningBalance, (string) $inc->amount, 2);
                }
            }

            $dayCommitments = '0.00';
            if (isset($occurrences[$dateStr])) {
                foreach ($occurrences[$dateStr] as $occ) {
                    $dayCommitments = bcadd($dayCommitments, (string) $occ->amount_due, 2);
                    $runningBalance = bcsub($runningBalance, (string) $occ->amount_due, 2);
                }
            }

            if (bccomp($runningBalance, '0.00', 2) < 0 && $deficitRiskDate === null) {
                $deficitRiskDate = $dateStr;
            }

            $dailyTimeline[] = [
                'date' => $dateStr,
                'income' => $dayIncome,
                'commitments' => $dayCommitments,
                'projected_balance' => $runningBalance,
            ];

            $cursor->addDay();
        }

        return response()->json([
            'initial_balance' => $initialBalance,
            'deficit_risk_date' => $deficitRiskDate,
            'timeline' => $dailyTimeline,
        ]);
    }

    private function category(string $name, string $forecast, string $actual): array
    {
        return ['category' => $name, 'forecast' => $forecast, 'actual' => $actual, 'variance' => bcsub($actual, $forecast, 2)];
    }

    private function commitmentForecastByMonth(int $userId, Carbon $from, Carbon $to, CommitmentRecurrence $recurrence): array
    {
        $occurrences = CommitmentOccurrence::query()
            ->where('user_id', $userId)
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->get(['commitment_id', 'due_date', 'status', 'amount_due'])
            ->keyBy(fn (CommitmentOccurrence $occurrence) => $occurrence->commitment_id.':'.$occurrence->due_date->toDateString());
        $commitments = Commitment::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereNotNull('next_due_date')
            ->where(function ($query) {
                $query->whereNull('type')->orWhere('type', '!=', 'credit_card');
            })
            ->get();
        $firstDueDates = CommitmentOccurrence::query()
            ->where('user_id', $userId)
            ->whereIn('commitment_id', $commitments->modelKeys())
            ->selectRaw('commitment_id, MIN(due_date) as first_due_date')
            ->groupBy('commitment_id')
            ->pluck('first_due_date', 'commitment_id');
        $totals = [];

        foreach ($commitments as $commitment) {
            $baseDay = Carbon::parse($firstDueDates->get($commitment->id) ?? $commitment->next_due_date)->day;
            $dueDate = Carbon::parse($commitment->next_due_date);
            $steps = 0;
            while ($dueDate < $from && $steps < 1200) {
                $dueDate = $recurrence->nextDueDate($dueDate, $commitment->frequency, $baseDay);
                if ($dueDate === null) break;
                $steps++;
            }
            while ($dueDate !== null && $dueDate <= $to && $steps < 1200) {
                $key = $commitment->id.':'.$dueDate->toDateString();
                $occurrence = $occurrences->get($key);
                if (($occurrence?->status ?? 'scheduled') !== 'paid') {
                    $month = $dueDate->format('Y-m');
                    $amount = $occurrence?->amount_due ?? $commitment->amount;
                    $totals[$month] = bcadd($totals[$month] ?? '0.00', (string) $amount, 2);
                }
                $dueDate = $recurrence->nextDueDate($dueDate, $commitment->frequency, $baseDay);
                $steps++;
            }
        }

        return $totals;
    }
}
