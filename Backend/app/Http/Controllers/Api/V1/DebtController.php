<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordDebtPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\IncreaseDebtBalanceRequest;
use App\Http\Requests\StoreDebtPaymentRequest;
use App\Http\Requests\StoreDebtRequest;
use App\Models\Account;
use App\Models\Commitment;
use App\Models\Debt;
use App\Models\DebtBalanceMovement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DebtController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = Debt::query()->where('user_id', $request->user()->id)->with('account')->orderBy('due_date')->get()
            ->map(fn (Debt $debt) => [
                'id' => $debt->id,
                'account' => $debt->name,
                'kind' => $debt->kind,
                'outstanding' => $debt->outstanding_amount,
                'minimum' => $debt->minimum_payment,
                'due_date' => $debt->due_date?->toDateString(),
                'last_four' => $debt->last_four,
            ]);
        return response()->json(['data' => $items]);
    }

    public function store(StoreDebtRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        $this->assertAccountOwnership($user, $validated['account_id'] ?? null);

        $result = DB::transaction(function () use ($validated, $user) {
            $existing = DebtBalanceMovement::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();
            if ($existing) {
                if ($existing->kind !== 'opening_balance'
                    || bccomp((string) $existing->delta, (string) $validated['outstanding_amount'], 2) !== 0) {
                    abort(409, 'Idempotency key was already used for a different operation.');
                }

                return ['debt' => Debt::query()->where('user_id', $user->id)->whereKey($existing->debt_id)->firstOrFail(), 'created' => false];
            }

            $debtAttributes = $validated;
            unset($debtAttributes['idempotency_key']);
            $debt = Debt::query()->create($debtAttributes + ['user_id' => $user->id]);
            DebtBalanceMovement::query()->create([
                'user_id' => $user->id,
                'debt_id' => $debt->id,
                'kind' => 'opening_balance',
                'delta' => $validated['outstanding_amount'],
                'description' => 'Initial balance',
                'occurred_at' => now(),
                'idempotency_key' => $validated['idempotency_key'],
            ]);

            return ['debt' => $debt, 'created' => true];
        });

        return response()->json(['data' => $this->serialize($result['debt'])], $result['created'] ? 201 : 200);
    }

    public function increase(IncreaseDebtBalanceRequest $request, int $debt): JsonResponse
    {
        $user = $request->user();
        $debtRecord = Debt::query()->where('user_id', $user->id)->whereKey($debt)->firstOrFail();
        $validated = $request->validated();

        $updated = DB::transaction(function () use ($debtRecord, $user, $validated) {
            $existing = DebtBalanceMovement::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();
            if ($existing) {
                if ((int) $existing->debt_id !== (int) $debtRecord->id
                    || $existing->kind !== 'increase'
                    || bccomp((string) $existing->delta, (string) $validated['amount'], 2) !== 0
                    || $existing->description !== ($validated['description'] ?? null)
                    || !$existing->occurred_at->equalTo(Carbon::parse($validated['occurred_at']))) {
                    abort(409, 'Idempotency key was already used for a different operation.');
                }

                return Debt::query()->where('user_id', $user->id)->whereKey($debtRecord->id)->firstOrFail();
            }

            $locked = Debt::query()->where('user_id', $user->id)->whereKey($debtRecord->id)->lockForUpdate()->firstOrFail();
            $locked->update(['outstanding_amount' => bcadd((string) $locked->outstanding_amount, (string) $validated['amount'], 2)]);
            DebtBalanceMovement::query()->create([
                'user_id' => $user->id,
                'debt_id' => $locked->id,
                'kind' => 'increase',
                'delta' => $validated['amount'],
                'description' => $validated['description'] ?? null,
                'occurred_at' => Carbon::parse($validated['occurred_at']),
                'idempotency_key' => $validated['idempotency_key'],
            ]);

            return $locked->refresh();
        }, 3);

        return response()->json(['data' => $this->serialize($updated)]);
    }

    public function payment(StoreDebtPaymentRequest $request, int $debt, RecordDebtPayment $action): JsonResponse
    {
        $record = Debt::query()->where('user_id', $request->user()->id)->whereKey($debt)->firstOrFail();
        $result = $action->execute($request->user(), $record, $request->validated());

        return response()->json([
            'data' => ['debt_id' => $result['debt']->id, 'paid' => $result['movement']->delta, 'outstanding' => $result['debt']->outstanding_amount],
        ], $result['created'] ? 201 : 200);
    }

    public function history(Request $request): JsonResponse
    {
        $movements = DebtBalanceMovement::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['delta', 'occurred_at']);

        $changesByMonth = [];
        foreach ($movements as $movement) {
            $month = $movement->occurred_at->format('Y-m');
            $changesByMonth[$month] = bcadd($changesByMonth[$month] ?? '0.00', (string) $movement->delta, 2);
        }

        $commitments = Commitment::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('type', ['loan', 'credit_card'])
            ->whereNotNull('opening_balance')
            ->with(['occurrences.payments' => fn ($query) => $query->whereNull('reversed_at')])
            ->get();

        foreach ($commitments as $commitment) {
            $month = $commitment->created_at->format('Y-m');
            $changesByMonth[$month] = bcadd($changesByMonth[$month] ?? '0.00', (string) $commitment->opening_balance, 2);
            foreach ($commitment->occurrences as $occurrence) {
                if ($occurrence->interest_accrued !== null && $occurrence->payments->isNotEmpty()) {
                    $month = $occurrence->due_date->format('Y-m');
                    $changesByMonth[$month] = bcadd($changesByMonth[$month] ?? '0.00', (string) $occurrence->interest_accrued, 2);
                }
                foreach ($occurrence->payments as $payment) {
                    $month = $payment->paid_at->format('Y-m');
                    $changesByMonth[$month] = bcsub($changesByMonth[$month] ?? '0.00', (string) $payment->amount, 2);
                }
            }
        }

        if ($changesByMonth === []) return response()->json(['data' => []]);

        ksort($changesByMonth);
        $month = Carbon::createFromFormat('Y-m', array_key_first($changesByMonth))->startOfMonth();
        $lastMonth = now()->startOfMonth();
        $balance = '0.00';
        $series = [];

        while ($month->lessThanOrEqualTo($lastMonth)) {
            $key = $month->format('Y-m');
            $balance = bcadd($balance, $changesByMonth[$key] ?? '0.00', 2);
            $series[] = [
                'month' => $key,
                'balance' => $balance,
                'balance_minor' => (int) round((float) $balance * 100),
            ];
            $month->addMonth();
        }

        return response()->json(['data' => $series]);
    }

    private function assertAccountOwnership(User $user, ?int $accountId): void
    {
        if ($accountId !== null) {
            abort_unless(Account::query()->where('user_id', $user->id)->whereKey($accountId)->exists(), 404);
        }
    }

    private function serialize(Debt $debt): array
    {
        return [
            'id' => $debt->id,
            'account' => $debt->name,
            'kind' => $debt->kind,
            'outstanding' => $debt->outstanding_amount,
            'minimum' => $debt->minimum_payment,
            'due_date' => $debt->due_date?->toDateString(),
            'last_four' => $debt->last_four,
        ];
    }
}
