<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordCommitmentPayment;
use App\Actions\RecalculateCommitmentAmortization;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommitmentPaymentRequest;
use App\Http\Requests\StoreCommitmentRequest;
use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use App\Models\DebtBalanceMovement;
use App\Services\DebtBalanceLedger;
use App\Support\CreditCardCycleDates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommitmentController extends Controller
{
    public function store(StoreCommitmentRequest $request, DebtBalanceLedger $balanceLedger): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();
        $minimumPayment = $data['minimum_payment'] ?? null;
        unset($data['minimum_payment']);

        if (($data['type'] ?? null) === 'credit_card') {
            $data['statement_balance'] = $data['statement_balance'] ?? $data['current_balance'];
            $data['amount'] = bccomp((string) $data['statement_balance'], '0.00', 2) > 0 ? $data['statement_balance'] : '0.01';
            $data['frequency'] = 'monthly';
            $data['opening_balance'] = $data['current_balance'];
            $data['approved_credit_limit'] = $data['approved_credit_limit'] ?? $data['credit_limit'] ?? null;
            $data['authorized_overdraft_limit'] = $data['authorized_overdraft_limit'] ?? '0.00';
            $data['limit_status'] = $data['limit_status'] ?? ($data['approved_credit_limit'] !== null ? 'confirmed' : 'unknown');

            $cycle = CreditCardCycleDates::schedule((int) $data['statement_day'], (int) $data['payment_grace_days']);
            $data['next_due_date'] = $cycle['next_due']->toDateString();
        } elseif (in_array($data['type'] ?? 'other', ['loan'], true)) {
            $data['opening_balance'] = $data['current_balance'];
        }

        $commitment = DB::transaction(function () use ($user, $data, $minimumPayment, $balanceLedger) {
            $commitment = Commitment::query()->create($data + ['user_id' => $user->id]);

            CommitmentOccurrence::query()->create([
                'user_id' => $user->id,
                'commitment_id' => $commitment->id,
                'amount_due' => $commitment->amount,
                'due_date' => $commitment->next_due_date,
            ]);

            if ($commitment->type === 'credit_card') {
                $approvedLimit = $data['approved_credit_limit'] ?? $data['credit_limit'] ?? null;
                $overdraftLimit = $data['authorized_overdraft_limit'] ?? '0.00';
                $limitStatus = $data['limit_status'] ?? ($approvedLimit !== null ? 'confirmed' : 'unknown');
                $specialRulesDocumented = (bool) ($data['special_rules_documented'] ?? false);
                $specialRulesSource = $data['special_rules_source'] ?? null;
                $specialRulesReference = $data['special_rules_reference'] ?? null;
                $cardProductName = $data['card_product_name'] ?? null;
                $cardVariant = $data['card_variant'] ?? null;
                $cardNetwork = $data['card_network'] ?? null;
                $cardCurrency = $data['card_currency'] ?? null;
                $issuedAt = $data['issued_at'] ?? null;
                $annualFee = $data['annual_fee'] ?? null;
                $replacementFee = $data['replacement_fee'] ?? null;
                $cashAdvanceFee = $data['cash_advance_fee'] ?? null;
                $lateFee = $data['late_fee'] ?? null;
                $cardBenefits = $data['card_benefits'] ?? null;
                $cardPaymentMethods = $data['card_payment_methods'] ?? null;
                $feeType = $data['overdraft_fee_type'] ?? 'none';
                $feeAmount = $data['overdraft_fee_amount'] ?? '0.00';
                $feePct = $data['overdraft_fee_percentage'] ?? '0.00';
                $feeTax = $data['overdraft_fee_tax_rate'] ?? '0.00';

                $commitment->update([
                    'credit_limit' => $approvedLimit,
                    'approved_credit_limit' => $approvedLimit,
                    'authorized_overdraft_limit' => $overdraftLimit,
                    'limit_status' => $limitStatus,
                    'special_rules_documented' => $specialRulesDocumented,
                    'special_rules_source' => $specialRulesSource,
                    'special_rules_reference' => $specialRulesReference,
                    'card_product_name' => $cardProductName,
                    'card_variant' => $cardVariant,
                    'card_network' => $cardNetwork,
                    'card_currency' => $cardCurrency,
                    'issued_at' => $issuedAt,
                    'annual_fee' => $annualFee,
                    'replacement_fee' => $replacementFee,
                    'cash_advance_fee' => $cashAdvanceFee,
                    'late_fee' => $lateFee,
                    'card_benefits' => $cardBenefits,
                    'card_payment_methods' => $cardPaymentMethods,
                    'overdraft_fee_type' => $feeType,
                    'overdraft_fee_amount' => $feeAmount,
                    'overdraft_fee_percentage' => $feePct,
                    'overdraft_fee_tax_rate' => $feeTax,
                ]);

                $account = \App\Models\Account::query()->where('user_id', $user->id)
                    ->where(function ($q) use ($commitment) {
                        $q->where('commitment_id', $commitment->id)->orWhere('name', $commitment->name);
                    })->first();

                if (!$account) {
                    $account = \App\Models\Account::query()->create([
                        'user_id' => $user->id,
                        'commitment_id' => $commitment->id,
                        'name' => $commitment->name,
                        'institution' => $commitment->institution,
                        'type' => 'credit_card',
                        'currency' => 'DOP',
                        'is_active' => true,
                        'last_four' => $commitment->reference_number,
                        'credit_limit' => $approvedLimit,
                        'approved_credit_limit' => $approvedLimit,
                        'authorized_overdraft_limit' => $overdraftLimit,
                        'limit_status' => $limitStatus,
                        'special_rules_documented' => $specialRulesDocumented,
                        'special_rules_source' => $specialRulesSource,
                        'special_rules_reference' => $specialRulesReference,
                        'card_product_name' => $cardProductName,
                        'card_variant' => $cardVariant,
                        'card_network' => $cardNetwork,
                        'card_currency' => $cardCurrency,
                        'issued_at' => $issuedAt,
                        'annual_fee' => $annualFee,
                        'replacement_fee' => $replacementFee,
                        'cash_advance_fee' => $cashAdvanceFee,
                        'late_fee' => $lateFee,
                        'card_benefits' => $cardBenefits,
                        'card_payment_methods' => $cardPaymentMethods,
                        'overdraft_fee_type' => $feeType,
                        'overdraft_fee_amount' => $feeAmount,
                        'overdraft_fee_percentage' => $feePct,
                        'overdraft_fee_tax_rate' => $feeTax,
                    ]);
                } else {
                    $account->update([
                        'commitment_id' => $commitment->id,
                        'institution' => $commitment->institution,
                        'last_four' => $commitment->reference_number,
                        'credit_limit' => $approvedLimit,
                        'approved_credit_limit' => $approvedLimit,
                        'authorized_overdraft_limit' => $overdraftLimit,
                        'limit_status' => $limitStatus,
                        'special_rules_documented' => $specialRulesDocumented,
                        'special_rules_source' => $specialRulesSource,
                        'special_rules_reference' => $specialRulesReference,
                        'card_product_name' => $cardProductName,
                        'card_variant' => $cardVariant,
                        'card_network' => $cardNetwork,
                        'card_currency' => $cardCurrency,
                        'issued_at' => $issuedAt,
                        'annual_fee' => $annualFee,
                        'replacement_fee' => $replacementFee,
                        'cash_advance_fee' => $cashAdvanceFee,
                        'late_fee' => $lateFee,
                        'card_benefits' => $cardBenefits,
                        'card_payment_methods' => $cardPaymentMethods,
                        'overdraft_fee_type' => $feeType,
                        'overdraft_fee_amount' => $feeAmount,
                        'overdraft_fee_percentage' => $feePct,
                        'overdraft_fee_tax_rate' => $feeTax,
                    ]);
                }

                $debt = \App\Models\Debt::query()->where('user_id', $user->id)
                    ->where(function ($q) use ($commitment, $account) {
                        $q->where('commitment_id', $commitment->id)
                          ->orWhere('account_id', $account->id)
                          ->orWhere('name', $commitment->name);
                    })->first();

                if (!$debt) {
                    $debt = \App\Models\Debt::query()->create([
                        'user_id' => $user->id,
                        'account_id' => $account->id,
                        'commitment_id' => $commitment->id,
                        'name' => $commitment->name,
                        'kind' => 'credit_card',
                        'outstanding_amount' => $commitment->current_balance,
                        'minimum_payment' => $minimumPayment !== null ? (string) $minimumPayment : '0.00',
                        'minimum_payment_confirmed' => $minimumPayment !== null,
                        'due_date' => $commitment->next_due_date,
                        'last_four' => $commitment->reference_number,
                        'statement_day' => $commitment->statement_day,
                        'payment_grace_days' => $commitment->payment_grace_days,
                        'statement_balance' => $commitment->statement_balance,
                        'credit_limit' => $approvedLimit,
                        'approved_credit_limit' => $approvedLimit,
                        'authorized_overdraft_limit' => $overdraftLimit,
                        'limit_status' => $limitStatus,
                        'special_rules_documented' => $specialRulesDocumented,
                        'special_rules_source' => $specialRulesSource,
                        'special_rules_reference' => $specialRulesReference,
                        'card_product_name' => $cardProductName,
                        'card_variant' => $cardVariant,
                        'card_network' => $cardNetwork,
                        'card_currency' => $cardCurrency,
                        'issued_at' => $issuedAt,
                        'annual_fee' => $annualFee,
                        'replacement_fee' => $replacementFee,
                        'cash_advance_fee' => $cashAdvanceFee,
                        'late_fee' => $lateFee,
                        'card_benefits' => $cardBenefits,
                        'card_payment_methods' => $cardPaymentMethods,
                        'overdraft_fee_type' => $feeType,
                        'overdraft_fee_amount' => $feeAmount,
                        'overdraft_fee_percentage' => $feePct,
                        'overdraft_fee_tax_rate' => $feeTax,
                    ]);
                } else {
                    if ($minimumPayment !== null) {
                        $debt->minimum_payment = (string) $minimumPayment;
                        $debt->minimum_payment_confirmed = true;
                    }
                    $debt->fill([
                        'account_id' => $account->id,
                        'commitment_id' => $commitment->id,
                        'kind' => 'credit_card',
                        'outstanding_amount' => $commitment->current_balance,
                        'due_date' => $commitment->next_due_date,
                        'last_four' => $commitment->reference_number,
                        'statement_day' => $commitment->statement_day,
                        'payment_grace_days' => $commitment->payment_grace_days,
                        'statement_balance' => $commitment->statement_balance,
                        'credit_limit' => $approvedLimit,
                        'approved_credit_limit' => $approvedLimit,
                        'authorized_overdraft_limit' => $overdraftLimit,
                        'limit_status' => $limitStatus,
                        'special_rules_documented' => $specialRulesDocumented,
                        'special_rules_source' => $specialRulesSource,
                        'special_rules_reference' => $specialRulesReference,
                        'overdraft_fee_type' => $feeType,
                        'overdraft_fee_amount' => $feeAmount,
                        'overdraft_fee_percentage' => $feePct,
                        'overdraft_fee_tax_rate' => $feeTax,
                    ])->save();
                }

                $account->update(['debt_id' => $debt->id]);
                $commitment->update(['account_id' => $account->id, 'debt_id' => $debt->id]);

                $balanceLedger->ensureOpeningBalance(
                    $debt,
                    $account->id,
                    now(),
                    'Initial credit card balance',
                );
                $balanceMovements = DebtBalanceMovement::query()
                    ->where('debt_id', $debt->id)
                    ->orderBy('occurred_at')
                    ->orderBy('id')
                    ->get(['delta']);
                $ledgerBalance = '0.00';
                foreach ($balanceMovements as $movement) {
                    $ledgerBalance = bcadd($ledgerBalance, (string) $movement->delta, 2);
                }
                $adjustment = bcsub((string) $debt->outstanding_amount, $ledgerBalance, 2);
                if (bccomp($adjustment, '0.00', 2) !== 0) {
                    DebtBalanceMovement::query()->create([
                        'user_id' => $user->id,
                        'debt_id' => $debt->id,
                        'account_id' => $account->id,
                        'kind' => 'adjustment',
                        'delta' => $adjustment,
                        'description' => 'Card balance reconciliation at setup',
                        'occurred_at' => now(),
                        'idempotency_key' => Str::uuid()->toString(),
                    ]);
                }
            }

            return $commitment;
        });

        return response()->json(['data' => $this->serializeCommitment($commitment)], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $status = $request->string('status')->toString() ?: 'active';
        $commitmentsQuery = Commitment::query()
            ->where('user_id', $userId)
            ->with('occurrences');

        if ($status === 'active') {
            $commitmentsQuery->where('is_active', true);
        } elseif ($status === 'inactive') {
            $commitmentsQuery->where('is_active', false);
        }

        $type = $request->string('type')->toString();
        if ($type !== '') {
            $commitmentsQuery->where('type', $type);
        }

        $institution = $request->string('institution')->toString();
        if ($institution !== '') {
            $commitmentsQuery->where('institution', 'like', '%' . $institution . '%');
        }

        $search = $request->string('search')->toString();
        if ($search !== '') {
            $commitmentsQuery->where(function ($query) use ($search) {
                $query->where('name', 'like', '%' . $search . '%')
                    ->orWhere('institution', 'like', '%' . $search . '%')
                    ->orWhere('reference_number', 'like', '%' . $search . '%');
            });
        }

        $sort = $request->string('sort')->toString() ?: 'due';
        if ($sort === 'amount') {
            $commitmentsQuery->orderByRaw('CAST(amount AS DECIMAL(20,2)) DESC');
        } elseif ($sort === 'name') {
            $commitmentsQuery->orderBy('name');
        } else {
            $commitmentsQuery->orderBy('next_due_date');
        }

        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);
        $commitments = $commitmentsQuery->paginate($perPage, ['*'], 'page', max((int) $request->integer('page', 1), 1));

        $occurrences = CommitmentOccurrence::query()->where('user_id', $userId)->with('commitment', 'payments')->orderBy('due_date')->get();

        $activeCommitments = Commitment::query()->where('user_id', $userId)->where('is_active', true)->get();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $periodOccurrences = CommitmentOccurrence::query()
            ->where('user_id', $userId)
            ->whereBetween('due_date', [$monthStart, $monthEnd])
            ->get();
        $pendingPeriod = '0.00';
        $overdue = '0.00';
        $paidPeriod = '0.00';
        foreach ($periodOccurrences as $occurrence) {
            $paid = $occurrence->payments()->whereNull('reversed_at')->sum('amount');
            $remaining = bcsub((string) $occurrence->amount_due, (string) $paid, 2);
            if (bccomp($remaining, '0.00', 2) > 0) {
                $pendingPeriod = bcadd($pendingPeriod, $remaining, 2);
            }
            $paidPeriod = bcadd($paidPeriod, (string) $paid, 2);
            if ($occurrence->due_date->lt(now()->startOfDay()) && bccomp($remaining, '0.00', 2) > 0) {
                $overdue = bcadd($overdue, $remaining, 2);
            }
        }
        $paidPeriod = (string) \App\Models\Payment::query()
            ->where('user_id', $userId)
            ->whereNull('reversed_at')
            ->whereNotNull('commitment_occurrence_id')
            ->whereBetween('paid_at', [$monthStart, $monthEnd])
            ->sum('amount');
        $pendingDebt = \App\Models\Debt::query()->where('user_id', $userId)->sum('outstanding_amount');

        return response()->json([
            'commitments' => collect($commitments->items())->map(fn (Commitment $item) => $this->serializeCommitment($item)),
            'occurrences' => $occurrences->map(function (CommitmentOccurrence $item) {
                $paid = $item->payments->whereNull('reversed_at')->sum('amount');
                $serialized = [
                    'id' => $item->id,
                    'commitment_id' => $item->commitment_id,
                    'name' => $item->commitment?->name,
                    'type' => $item->commitment?->type ?? 'other',
                    'reference_number' => $item->commitment?->reference_number,
                    'institution' => $item->commitment?->institution,
                    'current_balance' => $item->commitment?->current_balance,
                    'statement_balance' => $item->commitment?->statement_balance,
                    'statement_day' => $item->commitment?->statement_day,
                    'payment_grace_days' => $item->commitment?->payment_grace_days,
                    'approved_credit_limit' => $item->commitment?->approved_credit_limit ?? $item->commitment?->credit_limit,
                    'authorized_overdraft_limit' => $item->commitment?->authorized_overdraft_limit,
                    'limit_status' => $item->commitment?->limit_status ?? 'unknown',
                    'special_rules_documented' => (bool) $item->commitment?->special_rules_documented,
                    'special_rules_source' => $item->commitment?->special_rules_source,
                    'special_rules_reference' => $item->commitment?->special_rules_reference,
                    'interest_rate' => $item->commitment?->interest_rate,
                    'category' => $item->commitment?->category,
                    'maturity_date' => $item->commitment?->maturity_date?->toDateString(),
                    'notes' => $item->commitment?->notes,
                    'frequency' => $item->commitment?->frequency,
                    'amount' => $item->amount_due,
                    'remaining_amount' => bcsub((string) $item->amount_due, (string) $paid, 2),
                    'due_date' => $item->due_date->toDateString(),
                    'status' => $item->status,
                ];
                if (($item->commitment?->type ?? null) === 'credit_card' && $item->commitment->debt) {
                    $metrics = app(\App\Services\CreditCardAuthorizationEngine::class)
                        ->getCapacityMetrics($item->commitment->debt);
                    $serialized['capacity_metrics'] = $metrics;
                }
                return $serialized;
            }),
            'summary' => [
                'pending_period' => $pendingPeriod,
                'overdue' => $overdue,
                'paid_period' => $paidPeriod,
                'pending_debt' => (string) ($pendingDebt ?: '0.00'),
                'active_count' => $activeCommitments->count(),
            ],
            'meta' => [
                'total' => $commitments->total(),
                'per_page' => $commitments->perPage(),
                'current_page' => $commitments->currentPage(),
                'last_page' => $commitments->lastPage(),
            ],
        ]);
    }

    public function update(Request $request, int $commitment): JsonResponse
    {
        $record = Commitment::query()->where('user_id', $request->user()->id)->whereKey($commitment)->firstOrFail();
        $type = $request->input('type');
        if ($type === 'credit_card') {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:160'],
                'type' => ['required', 'in:credit_card'],
                'reference_number' => ['required', 'string', 'max:4', 'regex:/^\d{4}$/'],
                'institution' => ['required', 'string', 'max:160'],
                'current_balance' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
                'statement_balance' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
                'statement_day' => ['nullable', 'integer', 'between:1,31'],
                'payment_grace_days' => ['nullable', 'integer', 'between:1,31'],
                'interest_rate' => ['nullable', 'numeric', 'between:0,100'],
                'approved_credit_limit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
                'authorized_overdraft_limit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
                'limit_status' => ['sometimes', 'in:unknown,confirmed,pending_verification,special_rules'],
                'overdraft_fee_type' => ['nullable', 'in:none,fixed,percentage,cutoff_fixed,cutoff_percentage,cutoff_both,cutoff_daily'],
                'overdraft_fee_amount' => ['nullable', 'numeric', 'min:0'],
                'overdraft_fee_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'card_product_name' => ['nullable', 'string', 'max:160'],
                'card_variant' => ['nullable', 'string', 'max:160'],
                'card_network' => ['nullable', 'string', 'in:Visa,Mastercard,Amex,Discover,Otra'],
                'card_currency' => ['nullable', 'string', 'in:DOP,USD,EUR'],
                'issued_at' => ['nullable', 'date'],
                'annual_fee' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
                'replacement_fee' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
                'cash_advance_fee' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
                'late_fee' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
                'card_benefits' => ['nullable', 'array'],
                'card_benefits.*' => ['string', 'max:255'],
                'card_payment_methods' => ['nullable', 'array'],
                'card_payment_methods.*' => ['string', 'max:255'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]);

            DB::transaction(function () use ($record, $validated) {
                $attributes = [
                    'name' => $validated['name'],
                    'type' => 'credit_card',
                    'reference_number' => $validated['reference_number'],
                    'institution' => $validated['institution'],
                    'current_balance' => $validated['current_balance'],
                    'statement_balance' => $validated['statement_balance'] ?? $validated['current_balance'],
                    'statement_day' => $validated['statement_day'] ?? $record->statement_day,
                    'payment_grace_days' => $validated['payment_grace_days'] ?? $record->payment_grace_days,
                    'interest_rate' => $validated['interest_rate'] ?? $record->interest_rate ?? '0.00',
                    'approved_credit_limit' => $validated['approved_credit_limit'] ?? null,
                    'credit_limit' => $validated['approved_credit_limit'] ?? null,
                    'authorized_overdraft_limit' => $validated['authorized_overdraft_limit'] ?? '0.00',
                    'overdraft_fee_type' => $validated['overdraft_fee_type'] ?? 'none',
                    'overdraft_fee_amount' => $validated['overdraft_fee_amount'] ?? '0.00',
                    'overdraft_fee_percentage' => $validated['overdraft_fee_percentage'] ?? '0.00',
                    'limit_status' => $validated['limit_status'] ?? (($validated['approved_credit_limit'] ?? null) !== null ? 'confirmed' : 'unknown'),
                    'card_product_name' => $validated['card_product_name'] ?? null,
                    'card_variant' => $validated['card_variant'] ?? null,
                    'card_network' => $validated['card_network'] ?? null,
                    'card_currency' => $validated['card_currency'] ?? null,
                    'issued_at' => $validated['issued_at'] ?? null,
                    'annual_fee' => $validated['annual_fee'] ?? null,
                    'replacement_fee' => $validated['replacement_fee'] ?? null,
                    'cash_advance_fee' => $validated['cash_advance_fee'] ?? null,
                    'late_fee' => $validated['late_fee'] ?? null,
                    'card_benefits' => $validated['card_benefits'] ?? null,
                    'card_payment_methods' => $validated['card_payment_methods'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                ];
                // Recompute upcoming due date when the user edits the billing cycle.
                // Existing statements retain their historical due dates.
                $cycle = CreditCardCycleDates::schedule(
                    (int) $attributes['statement_day'],
                    (int) $attributes['payment_grace_days']
                );
                $attributes['next_due_date'] = $cycle['next_due']->toDateString();
                $record->update($attributes);

                if ($record->account) {
                    $record->account->update($attributes);
                }
                if ($record->debt) {
                    $record->debt->update($attributes + ['outstanding_amount' => $validated['current_balance']]);
                }
            });
        } else {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:160'],
                'type' => ['required', 'string', 'in:loan,utility,subscription,insurance,rent,other'],
                'amount' => ['required', 'numeric', 'gt:0'],
                'frequency' => ['required', 'string', 'in:once,weekly,biweekly,monthly,quarterly,yearly'],
                'next_due_date' => ['required', 'date'],
                'reference_number' => ['nullable', 'string', 'max:80'],
                'institution' => ['nullable', 'string', 'max:160'],
                'category' => ['nullable', 'string', 'max:64'],
                'maturity_date' => ['nullable', 'date'],
                'current_balance' => ['nullable', 'numeric', 'min:0'],
                'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]);

            DB::transaction(function () use ($record, $validated) {
                $record->update($validated);

                CommitmentOccurrence::query()
                    ->where('commitment_id', $record->id)
                    ->where('status', 'scheduled')
                    ->whereDoesntHave('payments')
                    ->update([
                        'amount_due' => $validated['amount'],
                    ]);
            });
        }

        return response()->json(['data' => $this->serializeCommitment($record->refresh())]);
    }

    public function pause(Request $request, int $commitment): JsonResponse
    {
        $record = Commitment::query()->where('user_id', $request->user()->id)->whereKey($commitment)->firstOrFail();

        DB::transaction(function () use ($record) {
            $record->update(['is_active' => false]);
            CommitmentOccurrence::query()
                ->where('commitment_id', $record->id)
                ->where('status', 'scheduled')
                ->whereDoesntHave('payments')
                ->delete();
        });

        return response()->json(['data' => $this->serializeCommitment($record->refresh())]);
    }

    public function resume(Request $request, int $commitment): JsonResponse
    {
        $record = Commitment::query()->where('user_id', $request->user()->id)->whereKey($commitment)->firstOrFail();

        DB::transaction(function () use ($record) {
            $record->update([
                'is_active' => true,
                'next_due_date' => $record->next_due_date ?? now()->toDateString(),
            ]);

            if (!$record->type || $record->type !== 'credit_card') {
                $hasPending = CommitmentOccurrence::query()
                    ->where('commitment_id', $record->id)
                    ->where('status', '!=', 'paid')
                    ->exists();
                if (!$hasPending) {
                    CommitmentOccurrence::query()->create([
                        'user_id' => $record->user_id,
                        'commitment_id' => $record->id,
                        'amount_due' => $record->amount,
                        'due_date' => $record->next_due_date,
                        'status' => 'scheduled',
                    ]);
                }
            }
        });

        return response()->json(['data' => $this->serializeCommitment($record->refresh())]);
    }

    public function destroy(Request $request, int $commitment): JsonResponse
    {
        $record = Commitment::query()->where('user_id', $request->user()->id)->whereKey($commitment)->firstOrFail();

        DB::transaction(function () use ($record) {
            $record->update(['is_active' => false]);

            CommitmentOccurrence::query()
                ->where('commitment_id', $record->id)
                ->where('status', 'scheduled')
                ->whereDoesntHave('payments')
                ->delete();
        });

        return response()->json(['data' => ['id' => $commitment, 'deleted' => true]]);
    }

    private function serializeCommitment(Commitment $item): array
    {
        $data = [
            'id' => $item->id,
            'name' => $item->name,
            'type' => $item->type,
            'amount' => $item->amount,
            'frequency' => $item->frequency,
            'next_due_date' => $item->next_due_date?->toDateString(),
            'reference_number' => $item->reference_number,
            'institution' => $item->institution,
            'current_balance' => $item->current_balance,
            'interest_rate' => $item->interest_rate,
            'statement_day' => $item->statement_day,
            'payment_grace_days' => $item->payment_grace_days,
            'statement_balance' => $item->statement_balance,
            'credit_limit' => $item->approved_credit_limit ?? $item->credit_limit,
            'approved_credit_limit' => $item->approved_credit_limit ?? $item->credit_limit,
            'authorized_overdraft_limit' => $item->authorized_overdraft_limit,
            'limit_status' => $item->limit_status ?? 'unknown',
            'special_rules_documented' => (bool) $item->special_rules_documented,
            'special_rules_source' => $item->special_rules_source,
            'special_rules_reference' => $item->special_rules_reference,
            'card_product_name' => $item->card_product_name,
            'card_variant' => $item->card_variant,
            'card_network' => $item->card_network,
            'card_currency' => $item->card_currency,
            'issued_at' => $item->issued_at?->toDateString(),
            'annual_fee' => $item->annual_fee,
            'replacement_fee' => $item->replacement_fee,
            'cash_advance_fee' => $item->cash_advance_fee,
            'late_fee' => $item->late_fee,
            'card_benefits' => $item->card_benefits,
            'card_payment_methods' => $item->card_payment_methods,
            'overdraft_fee_type' => $item->overdraft_fee_type,
            'overdraft_fee_amount' => $item->overdraft_fee_amount,
            'overdraft_fee_percentage' => $item->overdraft_fee_percentage,
            'category' => $item->category,
            'maturity_date' => $item->maturity_date?->toDateString(),
            'notes' => $item->notes,
            'is_active' => (bool) $item->is_active,
            'debt_id' => $item->debt_id,
            'account_id' => $item->account_id,
            'term_months' => $item->term_months,
            'amortization_type' => $item->amortization_type,
        ];

        if ($item->type === 'loan' && $item->current_balance !== null && $item->interest_rate !== null) {
            $engine = app(\App\Services\FinancialProductEngine::class);
            $conditions = $engine->resolveApplicableConditions($item);
            $principal = (string) $item->current_balance;
            $rate = (string) ($conditions['annual_interest_rate'] ?? $item->interest_rate);
            $termMonths = (int) ($item->term_months ?? $conditions['term_months'] ?? 12);
            $amortization = $engine->calculateFrenchAmortization($principal, $rate, $termMonths);
            $data['contract_conditions'] = [
                'annual_interest_rate' => $conditions['annual_interest_rate'],
                'amortization_type' => $conditions['amortization_type'],
                'term_months' => $conditions['term_months'],
                'source' => $conditions['explanation'],
            ];
            $data['amortization'] = $amortization;
        }

        if ($item->type === 'credit_card' && $item->debt) {
            $data['contract_conditions'] = app(\App\Services\FinancialProductEngine::class)->resolveApplicableConditions($item);
        }

        if ($item->type === 'credit_card' && $item->debt) {
            $engine = app(\App\Services\CreditCardAuthorizationEngine::class);
            $metrics = $engine->getCapacityMetrics($item->debt);
            $data['capacity_metrics'] = $metrics;
        }

        return $data;
    }

    public function payment(
        StoreCommitmentPaymentRequest $request,
        int $commitment,
        int $occurrence,
        RecordCommitmentPayment $action,
        RecalculateCommitmentAmortization $amortization,
    ): JsonResponse
    {
        $record = Commitment::query()->where('user_id', $request->user()->id)->whereKey($commitment)->firstOrFail();
        $due = CommitmentOccurrence::query()->where('user_id', $request->user()->id)->where('commitment_id', $record->id)->where('status', '!=', 'paid')->whereKey($occurrence)->firstOrFail();
        $result = $action->execute($request->user(), $due, $request->validated(), $amortization);
        return response()->json(['data' => ['id' => $result['payment']->id, 'amount' => $result['payment']->amount, 'paid_at' => $result['payment']->paid_at], 'remaining_due' => $result['remaining_due']], 201);
    }
}
