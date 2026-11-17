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

class DashboardController extends Controller
{
    public function summary(Request $request, MoneyFormatter $money): JsonResponse
    {
        $user = $request->user();
        $confirmed = (string) FinancialTransaction::query()->where('user_id', $user->id)->sum('amount');
        $expected = (string) Income::query()->where('user_id', $user->id)->whereDate('expected_date', '>=', now()->startOfMonth())->whereDate('expected_date', '<=', now()->endOfMonth())->sum('amount');
        $received = (string) Payment::query()->where('user_id', $user->id)->whereNotNull('income_id')->whereNull('reversed_at')->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount');
        $occurrences = CommitmentOccurrence::query()->where('user_id', $user->id)->whereBetween('due_date', [now()->startOfDay(), now()->addDays(7)->endOfDay()])->where('status', '!=', 'paid')->orderBy('due_date')->limit(5)->get();

        $dueSoon = '0.00';
        foreach ($occurrences as $occurrence) $dueSoon = bcadd($dueSoon, (string) $occurrence->amount_due, 2);
        return response()->json(['balances' => ['confirmed' => $money->dop($confirmed), 'accountCount' => Account::query()->where('user_id', $user->id)->where('is_active', true)->count()],
            'income' => ['expected' => $money->dop($expected), 'received' => $money->dop($received)],
            'commitments' => ['dueSoon' => $money->dop($dueSoon), 'dueSoonLabel' => $occurrences->first()?->commitment?->name ?? 'No commitments due soon'],
            'upcoming' => $occurrences->map(fn (CommitmentOccurrence $item) => ['label' => $item->commitment?->name ?? 'Commitment', 'dueDate' => $item->due_date->format('M j'), 'amount' => $money->dop((string) $item->amount_due), 'kind' => 'Commitment'])->values()]);
    }
}
