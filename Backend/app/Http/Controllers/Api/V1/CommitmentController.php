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
            $requestedStatus = $data['limit_status'] ?? null;
            if ($requestedStatus === null) {
                $data['limit_status'] = $data['approved_credit_limit'] !== null ? 'confirmed' : 'unknown';
            }
            if ($data['limit_status'] === 'special_rules'
                && (!($data['special_rules_documented'] ?? false)
                    || empty($data['special_rules_source'])
                    || empty($data['special_rules_reference']))) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'limit_status' => ['Las reglas especiales requieren documentación, fuente y referencia contractual verificables.'],
                ]);
            }

            $today = now();
            $statementDay = min((int) $data['statement_day'], $today->daysInMonth);
            $statementDate = now()->day($statementDay);

            if ($today->day < $statementDay) {
                $cutoffDate = $statementDate;
            } else {
                $cutoffDate = $statementDate->addMonthNoOverflow();
            }

            $data['next_due_date'] = $cutoffDate->copy()->addDays((int) $data['payment_grace_days'])->toDateString();
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
        $commitments = Commitment::query()->where('user_id', $userId)->where('is_active', true)->with('occurrences')->orderBy('next_due_date')->get();
        $occurrences = CommitmentOccurrence::query()->where('user_id', $userId)->with('commitment', 'payments')->orderBy('due_date')->get();
        return response()->json([
            'commitments' => $commitments->map(fn (Commitment $item) => $this->serializeCommitment($item)),
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
                'approved_credit_limit' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
                'authorized_overdraft_limit' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
                'limit_status' => ['required', 'in:unknown,confirmed,pending_verification,special_rules'],
                'special_rules_documented' => ['required_if:limit_status,special_rules', 'boolean'],
                'special_rules_source' => ['required_if:limit_status,special_rules', 'string', 'max:2048'],
                'special_rules_reference' => ['required_if:limit_status,special_rules', 'string', 'max:255'],
                'financial_institution_id' => ['nullable', 'integer', 'exists:financial_institutions,id'],
                'financial_product_variant_id' => ['nullable', 'integer', 'exists:financial_product_variants,id'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]);

            if ($validated['limit_status'] === 'special_rules'
                && (!($validated['special_rules_documented'] ?? false))) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'limit_status' => ['Las reglas especiales deben estar documentadas y vinculadas a una fuente verificable.'],
                ]);
            }

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
                    'approved_credit_limit' => $validated['approved_credit_limit'],
                    'credit_limit' => $validated['approved_credit_limit'],
                    'authorized_overdraft_limit' => $validated['authorized_overdraft_limit'],
                    'limit_status' => $validated['limit_status'],
                    'special_rules_documented' => (bool) ($validated['special_rules_documented'] ?? false),
                    'special_rules_source' => $validated['special_rules_source'] ?? null,
                    'special_rules_reference' => $validated['special_rules_reference'] ?? null,
                    'financial_institution_id' => $validated['financial_institution_id'] ?? $record->financial_institution_id,
                    'financial_product_variant_id' => $validated['financial_product_variant_id'] ?? $record->financial_product_variant_id,
                    'notes' => $validated['notes'] ?? null,
                ];
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
                'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
                'frequency' => ['required', 'string', 'in:once,weekly,biweekly,monthly,quarterly,yearly'],
                'next_due_date' => ['required', 'date'],
                'reference_number' => ['nullable', 'string', 'max:80'],
                'institution' => ['nullable', 'string', 'max:160'],
                'category' => ['nullable', 'string', 'max:64'],
                'maturity_date' => ['nullable', 'date'],
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
            'overdraft_fee_type' => $item->overdraft_fee_type,
            'category' => $item->category,
            'maturity_date' => $item->maturity_date?->toDateString(),
            'notes' => $item->notes,
        ];

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
