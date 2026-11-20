<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PiggyBankDeposit;
use App\Services\MoneyFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PiggyBankController extends Controller
{
    public function index(Request $request, MoneyFormatter $money): JsonResponse
    {
        $user = $request->user();
        $month = $request->query('month', now()->format('Y-m'));
        [$year, $monthNum] = explode('-', $month);

        $startOfMonth = Carbon::createFromDate((int) $year, (int) $monthNum, 1)->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        // Calculate overall balance
        $depositsSum = (string) (PiggyBankDeposit::query()
            ->where('user_id', $user->id)
            ->where('type', 'deposit')
            ->sum('amount') ?: '0.00');

        $withdrawalsSum = (string) (PiggyBankDeposit::query()
            ->where('user_id', $user->id)
            ->whereIn('type', ['withdrawal', 'reset'])
            ->sum('amount') ?: '0.00');

        $totalBalance = bcsub($depositsSum, $withdrawalsSum, 2);
        if (bccomp($totalBalance, '0.00', 2) < 0) {
            $totalBalance = '0.00';
        }

        // Monthly Deposits
        $monthlyDeposits = PiggyBankDeposit::query()
            ->where('user_id', $user->id)
            ->whereBetween('occurred_at', [$startOfMonth, $endOfMonth])
            ->orderByDesc('occurred_at')
            ->get();

        $monthlySavedNum = (float) $monthlyDeposits->where('type', 'deposit')->sum('amount');
        $monthlyWithdrawnNum = (float) $monthlyDeposits->whereIn('type', ['withdrawal', 'reset'])->sum('amount');
        $monthlySaved = number_format($monthlySavedNum, 2, '.', '');
        $monthlyWithdrawn = number_format($monthlyWithdrawnNum, 2, '.', '');

        // Group deposits by day for calendar view
        $calendar = [];
        foreach ($monthlyDeposits as $deposit) {
            $dateStr = $deposit->occurred_at->format('Y-m-d');
            if (!isset($calendar[$dateStr])) {
                $calendar[$dateStr] = [
                    'date' => $dateStr,
                    'day' => $deposit->occurred_at->day,
                    'total_amount' => '0.00',
                    'count' => 0,
                    'items' => [],
                ];
            }

            if ($deposit->type === 'deposit') {
                $calendar[$dateStr]['total_amount'] = bcadd($calendar[$dateStr]['total_amount'], (string) $deposit->amount, 2);
            } else {
                $calendar[$dateStr]['total_amount'] = bcsub($calendar[$dateStr]['total_amount'], (string) $deposit->amount, 2);
            }

            $calendar[$dateStr]['count']++;
            $calendar[$dateStr]['items'][] = [
                'id' => $deposit->id,
                'amount' => (string) $deposit->amount,
                'formatted_amount' => $money->dop((string) $deposit->amount),
                'type' => $deposit->type,
                'note' => $deposit->note,
                'time' => $deposit->occurred_at->format('h:i A'),
            ];
        }

        return response()->json([
            'data' => [
                'total_balance' => (string) $totalBalance,
                'formatted_balance' => $money->dop((string) $totalBalance),
                'monthly_summary' => [
                    'month' => $month,
                    'total_saved' => (string) $monthlySaved,
                    'formatted_saved' => $money->dop((string) $monthlySaved),
                    'total_withdrawn' => (string) $monthlyWithdrawn,
                    'formatted_withdrawn' => $money->dop((string) $monthlyWithdrawn),
                    'deposit_count' => $monthlyDeposits->where('type', 'deposit')->count(),
                ],
                'calendar' => array_values($calendar),
                'history' => $monthlyDeposits->map(fn (PiggyBankDeposit $item) => [
                    'id' => $item->id,
                    'amount' => (string) $item->amount,
                    'formatted_amount' => $money->dop((string) $item->amount),
                    'type' => $item->type,
                    'note' => $item->note,
                    'occurred_at' => $item->occurred_at->toDateString(),
                    'time' => $item->occurred_at->format('h:i A'),
                ]),
            ],
        ]);
    }

    public function store(Request $request, MoneyFormatter $money): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'type' => ['required', 'string', 'in:deposit,withdrawal'],
            'note' => ['nullable', 'string', 'max:40'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        $type = $validated['type'];
        $amount = (string) $validated['amount'];

        if ($type === 'withdrawal') {
            $depositsSum = (string) (PiggyBankDeposit::query()->where('user_id', $user->id)->where('type', 'deposit')->sum('amount') ?: '0.00');
            $withdrawalsSum = (string) (PiggyBankDeposit::query()->where('user_id', $user->id)->whereIn('type', ['withdrawal', 'reset'])->sum('amount') ?: '0.00');
            $currentBal = bcsub($depositsSum, $withdrawalsSum, 2);

            if (bccomp($currentBal, $amount, 2) < 0) {
                throw ValidationException::withMessages([
                    'amount' => [sprintf('No tienes fondos suficientes en tu alcancía. Saldo disponible: RD$ %s', number_format((float) $currentBal, 2))],
                ]);
            }
        }

        $deposit = DB::transaction(function () use ($user, $validated, $amount, $type) {
            return PiggyBankDeposit::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'type' => $type,
                'note' => $validated['note'] ?? null,
                'occurred_at' => isset($validated['occurred_at']) ? Carbon::parse($validated['occurred_at']) : now(),
            ]);
        });

        // Recalculate new total
        $depositsSum = (string) (PiggyBankDeposit::query()->where('user_id', $user->id)->where('type', 'deposit')->sum('amount') ?: '0.00');
        $withdrawalsSum = (string) (PiggyBankDeposit::query()->where('user_id', $user->id)->whereIn('type', ['withdrawal', 'reset'])->sum('amount') ?: '0.00');
        $newBalance = bcsub($depositsSum, $withdrawalsSum, 2);

        return response()->json([
            'data' => [
                'id' => $deposit->id,
                'amount' => (string) $deposit->amount,
                'formatted_amount' => $money->dop((string) $deposit->amount),
                'type' => $deposit->type,
                'note' => $deposit->note,
                'new_balance' => (string) $newBalance,
                'formatted_new_balance' => $money->dop((string) $newBalance),
            ],
        ], 201);
    }

    public function reset(Request $request, MoneyFormatter $money): JsonResponse
    {
        $user = $request->user();

        $depositsSum = (string) (PiggyBankDeposit::query()->where('user_id', $user->id)->where('type', 'deposit')->sum('amount') ?: '0.00');
        $withdrawalsSum = (string) (PiggyBankDeposit::query()->where('user_id', $user->id)->whereIn('type', ['withdrawal', 'reset'])->sum('amount') ?: '0.00');
        $currentBal = bcsub($depositsSum, $withdrawalsSum, 2);

        if (bccomp($currentBal, '0.00', 2) <= 0) {
            return response()->json([
                'data' => [
                    'message' => 'La alcancía ya se encuentra vacía.',
                    'new_balance' => '0.00',
                    'formatted_new_balance' => $money->dop('0.00'),
                ],
            ]);
        }

        PiggyBankDeposit::create([
            'user_id' => $user->id,
            'amount' => $currentBal,
            'type' => 'reset',
            'note' => 'Alcancía vaciada',
            'occurred_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'message' => 'Alcancía vaciada con éxito.',
                'new_balance' => '0.00',
                'formatted_new_balance' => $money->dop('0.00'),
            ],
        ]);
    }
}
