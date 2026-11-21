<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CommitmentOccurrence;
use App\Models\Commitment;
use App\Models\Debt;
use App\Models\Income;
use App\Models\Reserve;
use App\Services\CommitmentRecurrence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CalendarController extends Controller
{
    public function index(Request $request, CommitmentRecurrence $recurrence): JsonResponse
    {
        $range = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $userId = $request->user()->id;
        $income = Income::query()->where('user_id', $userId)->whereBetween('expected_date', [$range['from'], $range['to']])->get()
            ->map(fn (Income $item) => ['event' => $item->source, 'date' => $item->expected_date->toDateString(), 'amount' => $item->amount, 'type' => 'income']);
        $occurrences = CommitmentOccurrence::query()->where('user_id', $userId)->whereBetween('due_date', [$range['from'], $range['to']])->with('commitment')->get();
        $commitments = $occurrences
            ->filter(fn (CommitmentOccurrence $item) => $item->commitment?->type !== 'credit_card')
            ->map(fn (CommitmentOccurrence $item) => ['event' => $item->commitment?->name ?? 'Commitment', 'date' => $item->due_date->toDateString(), 'amount' => $item->amount_due, 'type' => 'commitment', 'commitment_id' => $item->commitment_id]);
        $knownDates = $occurrences->keyBy(fn (CommitmentOccurrence $item) => $item->commitment_id.':'.$item->due_date->toDateString());
        $from = Carbon::parse($range['from']);
        $to = Carbon::parse($range['to']);
        $activeCommitments = Commitment::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereNotNull('next_due_date')
            ->get();
        $firstDueDates = CommitmentOccurrence::query()
            ->where('user_id', $userId)
            ->whereIn('commitment_id', $activeCommitments->modelKeys())
            ->selectRaw('commitment_id, MIN(due_date) as first_due_date')
            ->groupBy('commitment_id')
            ->pluck('first_due_date', 'commitment_id');

        foreach ($activeCommitments as $commitment) {
            if ($commitment->type === 'credit_card') {
                continue;
            }

            $baseDay = Carbon::parse($firstDueDates->get($commitment->id) ?? $commitment->next_due_date)->day;
            $dueDate = Carbon::parse($commitment->next_due_date);
            $steps = 0;
            while ($dueDate < $from && $steps < 1200) {
                $dueDate = $recurrence->nextDueDate($dueDate, $commitment->frequency, $baseDay);
                if ($dueDate === null) break;
                $steps++;
            }
            while ($dueDate !== null && $dueDate <= $to && $steps < 1200) {
                $known = $knownDates->get($commitment->id.':'.$dueDate->toDateString());
                if ($known === null) {
                    $commitments->push(['event' => $commitment->name, 'date' => $dueDate->toDateString(), 'amount' => $commitment->amount, 'type' => 'commitment', 'commitment_id' => $commitment->id]);
                }
                $dueDate = $recurrence->nextDueDate($dueDate, $commitment->frequency, $baseDay);
                $steps++;
            }
        }
        $debts = Debt::query()->where('user_id', $userId)->whereBetween('due_date', [$range['from'], $range['to']])->get()
            ->map(fn (Debt $item) => [
                'event' => $item->name.' payment due',
                'date' => $item->due_date->toDateString(),
                'amount' => $item->minimum_payment_confirmed ? $item->minimum_payment : null,
                'type' => 'debt',
            ]);
        return response()->json(['events' => $income->concat($commitments)->concat($debts)->sortBy('date')->values()]);
    }

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year_month' => ['sometimes', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $userId = $request->user()->id;
        $start = isset($validated['year_month']) ? Carbon::parse($validated['year_month'] . '-01')->startOfMonth() : now()->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $today = now()->startOfDay();

        $overdueOccurrences = CommitmentOccurrence::query()
            ->where('user_id', $userId)
            ->where('due_date', '<', $today)
            ->where('status', '!=', 'paid')
            ->with('payments')
            ->get();

        $totalOverdue = '0.00';
        foreach ($overdueOccurrences as $occ) {
            $paid = (string) $occ->payments->whereNull('reversed_at')->sum('amount');
            $remaining = bcsub((string) $occ->amount_due, $paid, 2);
            if (bccomp($remaining, '0.00', 2) > 0) {
                $totalOverdue = bcadd($totalOverdue, $remaining, 2);
            }
        }

        $monthOccurrences = CommitmentOccurrence::query()
            ->where('user_id', $userId)
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->with('payments')
            ->get();

        $totalScheduled = '0.00';
        $totalPaid = '0.00';
        $totalPending = '0.00';

        foreach ($monthOccurrences as $occ) {
            $due = (string) $occ->amount_due;
            $paid = (string) $occ->payments->whereNull('reversed_at')->sum('amount');
            $remaining = bcsub($due, $paid, 2);

            $totalScheduled = bcadd($totalScheduled, $due, 2);
            $totalPaid = bcadd($totalPaid, $paid, 2);
            if (bccomp($remaining, '0.00', 2) > 0) {
                $totalPending = bcadd($totalPending, $remaining, 2);
            }
        }

        $totalReserved = (string) Reserve::query()->where('user_id', $userId)->sum('current_amount');
        $totalUncovered = bccomp($totalPending, $totalReserved, 2) > 0
            ? bcsub($totalPending, $totalReserved, 2)
            : '0.00';

        return response()->json([
            'month' => $start->format('Y-m'),
            'total_scheduled' => $totalScheduled,
            'total_paid' => $totalPaid,
            'total_pending' => $totalPending,
            'total_overdue' => $totalOverdue,
            'total_reserved' => $totalReserved,
            'total_uncovered' => $totalUncovered,
        ]);
    }
}
