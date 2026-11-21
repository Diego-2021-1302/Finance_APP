<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordDebtPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\IncreaseDebtBalanceRequest;
use App\Http\Requests\StoreDebtPaymentRequest;
use App\Http\Requests\StoreDebtRequest;
use App\Http\Requests\UpdateDebtMinimumPaymentRequest;
use App\Models\Account;
use App\Models\Commitment;
use App\Models\CreditCardStatement;
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
            ->map(fn (Debt $debt) => $this->serialize($debt));
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
            $minimumConfirmed = array_key_exists('minimum_payment', $validated) && $validated['minimum_payment'] !== null;
            $debtAttributes['minimum_payment'] = $minimumConfirmed ? $validated['minimum_payment'] : '0.00';
            $debtAttributes['minimum_payment_confirmed'] = $minimumConfirmed;
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

    public function updateMinimumPayment(UpdateDebtMinimumPaymentRequest $request, int $debt): JsonResponse
    {
        $record = DB::transaction(function () use ($request, $debt) {
            $record = Debt::query()
                ->where('user_id', $request->user()->id)
                ->whereKey($debt)
                ->lockForUpdate()
                ->firstOrFail();
            $record->update([
                'minimum_payment' => $request->validated('minimum_payment'),
                'minimum_payment_confirmed' => true,
                'due_date' => $request->validated('due_date', $record->due_date?->toDateString()),
            ]);
            $statement = CreditCardStatement::query()
                ->where('user_id', $request->user()->id)
                ->where('debt_id', $record->id)
                ->orderByDesc('statement_date')
                ->lockForUpdate()
                ->first();
            if ($statement) {
                $statement->update([
                    'minimum_payment' => $request->validated('minimum_payment'),
                    'minimum_payment_confirmed' => true,
                    'minimum_payment_confirmed_at' => now(),
                    'due_date' => $record->due_date,
                ]);
            }

            return $record->refresh();
        }, 3);

        return response()->json(['data' => $this->serialize($record)]);
    }

    public function statements(Request $request, int $debt): JsonResponse
    {
        $record = Debt::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($debt)
            ->where('kind', 'credit_card')
            ->firstOrFail();
        $statements = CreditCardStatement::query()
            ->where('user_id', $request->user()->id)
            ->where('debt_id', $record->id)
            ->orderByDesc('statement_date')
            ->get()
            ->map(fn (CreditCardStatement $statement) => [
                'id' => $statement->id,
                'statement_date' => $statement->statement_date->toDateString(),
                'closing_balance' => $statement->closing_balance,
                'minimum_payment' => $statement->minimum_payment_confirmed ? $statement->minimum_payment : null,
                'minimum_payment_confirmed' => $statement->minimum_payment_confirmed,
                'due_date' => $statement->due_date->toDateString(),
            ]);

        return response()->json(['data' => $statements]);
    }

    public function payment(StoreDebtPaymentRequest $request, int $debt, RecordDebtPayment $action): JsonResponse
    {
        $record = Debt::query()->where('user_id', $request->user()->id)->whereKey($debt)->firstOrFail();
        $result = $action->execute($request->user(), $record, $request->validated());

        return response()->json([
            'data' => ['debt_id' => $result['debt']->id, 'paid' => $result['movement']->delta, 'outstanding' => $result['debt']->outstanding_amount],
        ], $result['created'] ? 201 : 200);
    }

    public function authorizeCardPreview(Request $request, int $debt): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $record = Debt::query()
            ->where('user_id', $request->user()->id)
            ->where('kind', 'credit_card')
            ->whereKey($debt)
            ->lockForUpdate()
            ->firstOrFail();

        $authorization = app(\App\Services\CreditCardAuthorizationEngine::class)
            ->authorizeTransaction($record, (string) $validated['amount'], null);
        $conditions = app(\App\Services\FinancialProductEngine::class)
            ->resolveApplicableConditions($record, $request->input('occurred_at') ?: now()->toDateString());

        return response()->json([
            'data' => [
                'previous_balance' => $record->outstanding_amount,
                'projected_balance' => bcadd((string) $record->outstanding_amount, $authorization['total_charge'], 2),
                'purchase_amount' => $validated['amount'],
                'approved_limit' => $authorization['metrics']['approved_credit_limit'],
                'authorized_overdraft_limit' => $authorization['metrics']['authorized_overdraft_limit'],
                'projected_overdraft' => $authorization['metrics']['used_overdraft'],
                'available_credit' => $authorization['metrics']['remaining_approved_credit'],
                'available_overdraft' => $authorization['metrics']['remaining_overdraft'],
                'uses_overdraft' => $authorization['uses_overdraft'],
                'fee' => $authorization['fee'],
                'tax' => $authorization['tax'],
                'total_fee' => $authorization['total_fee'],
                'total_charge' => $authorization['total_charge'],
                'fee_rule' => $authorization['fee_rule'],
                'contractual_conditions' => [
                    'annual_interest_rate' => $conditions['annual_interest_rate'],
                    'is_promotional' => $conditions['is_promotional'],
                    'effective_source' => $conditions['explanation']['rate_source'],
                    'fee_source' => $conditions['explanation']['fee_source'],
                    'verified_official_source' => $conditions['explanation']['verified_official_source'],
                    'rule_used' => $conditions['explanation']['rule_used'],
                    'operation_date' => $request->input('occurred_at') ?: now()->toDateString(),
                ],
            ],
        ]);
    }

    public function updateCardLimits(Request $request, int $debt): JsonResponse
    {
        $validated = $request->validate([
            'approved_credit_limit' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'authorized_overdraft_limit' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'limit_status' => ['required', 'in:confirmed,pending_verification,special_rules'],
            'special_rules_documented' => ['required_if:limit_status,special_rules', 'boolean'],
            'special_rules_source' => ['required_if:limit_status,special_rules', 'string', 'max:2048'],
            'special_rules_reference' => ['required_if:limit_status,special_rules', 'string', 'max:255'],
        ]);

        $record = DB::transaction(function () use ($request, $debt, $validated) {
            $record = Debt::query()
                ->where('user_id', $request->user()->id)
                ->where('kind', 'credit_card')
                ->whereKey($debt)
                ->lockForUpdate()
                ->firstOrFail();

            if ($validated['limit_status'] === 'special_rules'
                && (!($validated['special_rules_documented'] ?? false))) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'limit_status' => ['Las reglas especiales deben estar documentadas y vinculadas a una fuente verificable.'],
                ]);
            }

            $attributes = [
                'approved_credit_limit' => $validated['approved_credit_limit'],
                'credit_limit' => $validated['approved_credit_limit'],
                'authorized_overdraft_limit' => $validated['authorized_overdraft_limit'],
                'limit_status' => $validated['limit_status'],
                'special_rules_documented' => (bool) ($validated['special_rules_documented'] ?? false),
                'special_rules_source' => $validated['special_rules_source'] ?? null,
                'special_rules_reference' => $validated['special_rules_reference'] ?? null,
            ];

            $record->update($attributes);

            if ($record->account_id) {
                Account::query()->whereKey($record->account_id)
                    ->where('user_id', $request->user()->id)
                    ->update($attributes);
            }
            if ($record->commitment_id) {
                Commitment::query()->whereKey($record->commitment_id)
                    ->where('user_id', $request->user()->id)
                    ->update($attributes);
            }

            return $record->refresh();
        }, 3);

        return response()->json(['data' => $this->serialize($record)]);
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
            'minimum' => $debt->minimum_payment_confirmed ? $debt->minimum_payment : null,
            'minimum_confirmed' => $debt->minimum_payment_confirmed,
            'due_date' => $debt->due_date?->toDateString(),
            'last_four' => $debt->last_four,
            'approved_credit_limit' => $debt->approved_credit_limit ?? $debt->credit_limit,
            'authorized_overdraft_limit' => $debt->authorized_overdraft_limit,
            'limit_status' => $debt->limit_status ?? 'unknown',
            'special_rules_documented' => (bool) $debt->special_rules_documented,
            'special_rules_source' => $debt->special_rules_source,
            'special_rules_reference' => $debt->special_rules_reference,
            'commitment_id' => $debt->commitment_id,
            'account_id' => $debt->account_id,
            'term_months' => $debt->term_months,
            'amortization_type' => $debt->amortization_type,
        ];
    }
}
