<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CommitmentOccurrence;
use App\Models\Commitment;
use App\Models\Debt;
use App\Models\Income;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\CarbonImmutable;

class CalendarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $range = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $userId = $request->user()->id;
        $income = Income::query()->where('user_id', $userId)->whereBetween('expected_date', [$range['from'], $range['to']])->get()
            ->map(fn (Income $item) => ['event' => $item->source, 'date' => $item->expected_date->toDateString(), 'amount' => $item->amount, 'type' => 'income']);
        $occurrences = CommitmentOccurrence::query()->where('user_id', $userId)->whereBetween('due_date', [$range['from'], $range['to']])->with('commitment')->get();
        $commitments = $occurrences
            ->map(fn (CommitmentOccurrence $item) => ['event' => $item->commitment?->name ?? 'Commitment', 'date' => $item->due_date->toDateString(), 'amount' => $item->amount_due, 'type' => 'commitment']);
        $knownDates = $occurrences->keyBy(fn (CommitmentOccurrence $item) => $item->commitment_id.':'.$item->due_date->toDateString());
        $from = CarbonImmutable::parse($range['from']);
        $to = CarbonImmutable::parse($range['to']);
        foreach (Commitment::query()->where('user_id', $userId)->where('is_active', true)->whereNotNull('next_due_date')->get() as $commitment) {
            $dueDate = CarbonImmutable::parse($commitment->next_due_date);
            $steps = 0;
            while ($dueDate < $from && $steps < 1200) {
                $dueDate = $this->nextDueDate($dueDate, $commitment->frequency);
                if ($dueDate === null) break;
                $steps++;
            }
            while ($dueDate !== null && $dueDate <= $to && $steps < 1200) {
                $known = $knownDates->get($commitment->id.':'.$dueDate->toDateString());
                if ($known === null) {
                    $commitments->push(['event' => $commitment->name, 'date' => $dueDate->toDateString(), 'amount' => $commitment->amount, 'type' => 'commitment']);
                }
                $dueDate = $this->nextDueDate($dueDate, $commitment->frequency);
                $steps++;
            }
        }
        $debts = Debt::query()->where('user_id', $userId)->whereBetween('due_date', [$range['from'], $range['to']])->get()
            ->map(fn (Debt $item) => ['event' => $item->name.' payment due', 'date' => $item->due_date->toDateString(), 'amount' => $item->minimum_payment, 'type' => 'debt']);
        return response()->json(['events' => $income->concat($commitments)->concat($debts)->sortBy('date')->values()]);
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
