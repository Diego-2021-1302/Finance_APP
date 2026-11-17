<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CommitmentOccurrence;
use App\Models\Commitment;
use App\Models\Debt;
use App\Models\DebtBalanceMovement;
use App\Models\Income;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\CarbonImmutable;

class ReportController extends Controller
{
    public function cashFlow(Request $request): JsonResponse
    {
        $range = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'group_by' => ['sometimes', 'in:day,week,month,category']]);
        $userId = $request->user()->id;
        $fromDate = CarbonImmutable::parse($range['from']);
        $toDate = CarbonImmutable::parse($range['to']);
        $incomeForecast = (string) Income::query()->where('user_id', $userId)->whereBetween('expected_date', [$range['from'], $range['to']])->sum('amount');
        $commitmentForecastByMonth = $this->commitmentForecastByMonth($userId, $fromDate, $toDate);
        $commitmentForecast = '0.00';
        foreach ($commitmentForecastByMonth as $amount) $commitmentForecast = bcadd($commitmentForecast, $amount, 2);
        $commitmentForecast = bcsub('0.00', $commitmentForecast, 2);
        $debtForecast = bcsub('0.00', (string) Debt::query()->where('user_id', $userId)->whereBetween('due_date', [$range['from'], $range['to']])->sum('minimum_payment'), 2);
        $incomeActual = (string) Payment::query()->where('user_id', $userId)->whereNotNull('income_id')->whereNull('reversed_at')->whereBetween('paid_at', [$fromDate->startOfDay(), $toDate->endOfDay()])->sum('amount');
        $commitmentActual = bcsub('0.00', (string) Payment::query()->where('user_id', $userId)->whereNotNull('commitment_occurrence_id')->whereNull('reversed_at')->whereBetween('paid_at', [$fromDate->startOfDay(), $toDate->endOfDay()])->sum('amount'), 2);
        $debtActual = (string) DebtBalanceMovement::query()->where('user_id', $userId)->where('kind', 'payment')->whereBetween('occurred_at', [$fromDate->startOfDay(), $toDate->endOfDay()])->sum('delta');

        $categories = [
            $this->category('Income', $incomeForecast, $incomeActual),
            $this->category('Commitments', $commitmentForecast, $commitmentActual),
            $this->category('Debt payments', $debtForecast, $debtActual),
        ];
        $forecastTotal = bcadd(bcadd($incomeForecast, $commitmentForecast, 2), $debtForecast, 2);
        $actualTotal = bcadd(bcadd($incomeActual, $commitmentActual, 2), $debtActual, 2);
        $series = [];
        $cursor = CarbonImmutable::parse($range['from'])->startOfMonth();
        $lastMonth = CarbonImmutable::parse($range['to'])->startOfMonth();
        while ($cursor <= $lastMonth) {
            $monthStart = $cursor->max(CarbonImmutable::parse($range['from']));
            $monthEnd = $cursor->endOfMonth()->min(CarbonImmutable::parse($range['to']));
            $forecastIncome = (string) Income::query()->where('user_id', $userId)->whereBetween('expected_date', [$monthStart->toDateString(), $monthEnd->toDateString()])->sum('amount');
            $forecastCommitments = bcsub('0.00', $commitmentForecastByMonth[$cursor->format('Y-m')] ?? '0.00', 2);
            $forecastDebts = bcsub('0.00', (string) Debt::query()->where('user_id', $userId)->whereBetween('due_date', [$monthStart->toDateString(), $monthEnd->toDateString()])->sum('minimum_payment'), 2);
            $actualIncome = (string) Payment::query()->where('user_id', $userId)->whereNotNull('income_id')->whereNull('reversed_at')->whereBetween('paid_at', [$monthStart->startOfDay(), $monthEnd->endOfDay()])->sum('amount');
            $actualCommitments = bcsub('0.00', (string) Payment::query()->where('user_id', $userId)->whereNotNull('commitment_occurrence_id')->whereNull('reversed_at')->whereBetween('paid_at', [$monthStart->startOfDay(), $monthEnd->endOfDay()])->sum('amount'), 2);
            $actualDebtPayments = (string) DebtBalanceMovement::query()->where('user_id', $userId)->where('kind', 'payment')->whereBetween('occurred_at', [$monthStart->startOfDay(), $monthEnd->endOfDay()])->sum('delta');
            $series[] = [
                'month' => $cursor->format('M'),
                'forecastMinor' => (int) bcmul(bcadd(bcadd($forecastIncome, $forecastCommitments, 2), $forecastDebts, 2), '100', 0),
                'actualMinor' => (int) bcmul(bcadd(bcadd($actualIncome, $actualCommitments, 2), $actualDebtPayments, 2), '100', 0),
            ];
            $cursor = $cursor->addMonth();
        }
        return response()->json(['totals' => ['forecast' => $forecastTotal, 'actual' => $actualTotal, 'variance' => bcsub($actualTotal, $forecastTotal, 2)], 'series' => $series, 'categories' => $categories]);
    }

    private function category(string $name, string $forecast, string $actual): array
    {
        return ['category' => $name, 'forecast' => $forecast, 'actual' => $actual, 'variance' => bcsub($actual, $forecast, 2)];
    }

    private function commitmentForecastByMonth(int $userId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $occurrences = CommitmentOccurrence::query()
            ->where('user_id', $userId)
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->get(['commitment_id', 'due_date', 'status'])
            ->keyBy(fn (CommitmentOccurrence $occurrence) => $occurrence->commitment_id.':'.$occurrence->due_date->toDateString());
        $commitments = Commitment::query()->where('user_id', $userId)->where('is_active', true)->whereNotNull('next_due_date')->get();
        $totals = [];

        foreach ($commitments as $commitment) {
            $dueDate = CarbonImmutable::parse($commitment->next_due_date);
            $steps = 0;
            while ($dueDate < $from && $steps < 1200) {
                $dueDate = $this->nextDueDate($dueDate, $commitment->frequency);
                if ($dueDate === null) break;
                $steps++;
            }
            while ($dueDate !== null && $dueDate <= $to && $steps < 1200) {
                $key = $commitment->id.':'.$dueDate->toDateString();
                if (($occurrences->get($key)?->status ?? 'scheduled') !== 'paid') {
                    $month = $dueDate->format('Y-m');
                    $totals[$month] = bcadd($totals[$month] ?? '0.00', (string) $commitment->amount, 2);
                }
                $dueDate = $this->nextDueDate($dueDate, $commitment->frequency);
                $steps++;
            }
        }

        return $totals;
    }

    private function nextDueDate(CarbonImmutable $date, string $frequency): ?CarbonImmutable
    {
        return match ($frequency) {
            'weekly' => $date->addWeek(),
            'biweekly' => $date->addWeeks(2),
            'monthly' => $date->addMonthNoOverflow(),
            'quarterly' => $date->addMonthsNoOverflow(3),
            'yearly' => $date->addYearNoOverflow(),
            default => null,
        };
    }

}
