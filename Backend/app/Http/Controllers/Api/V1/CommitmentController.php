<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordCommitmentPayment;
use App\Actions\RecalculateCommitmentAmortization;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommitmentPaymentRequest;
use App\Http\Requests\StoreCommitmentRequest;
use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommitmentController extends Controller
{
    public function store(StoreCommitmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        if (($data['type'] ?? null) === 'credit_card') {
            $data['statement_balance'] = $data['statement_balance'] ?? $data['current_balance'];
            $data['amount'] = bccomp((string) $data['statement_balance'], '0.00', 2) > 0 ? $data['statement_balance'] : '0.00';
            $data['frequency'] = 'monthly';
            $data['opening_balance'] = $data['current_balance'];

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

        $commitment = DB::transaction(function () use ($user, $data) {
            $commitment = Commitment::query()->create($data + ['user_id' => $user->id]);

            CommitmentOccurrence::query()->create([
                'user_id' => $user->id,
                'commitment_id' => $commitment->id,
                'amount_due' => $commitment->amount,
                'due_date' => $commitment->next_due_date,
            ]);

            if ($commitment->type === 'credit_card') {
                $account = \App\Models\Account::query()->firstOrCreate(
                    ['user_id' => $user->id, 'name' => $commitment->name],
                    [
                        'institution' => $commitment->institution,
                        'type' => 'credit_card',
                        'currency' => 'DOP',
                        'is_active' => true,
                        'last_four' => $commitment->reference_number,
                    ]
                );

                $minimum = (string) round(((float) $commitment->statement_balance) * 0.05, 2);

                \App\Models\Debt::query()->updateOrCreate(
                    ['user_id' => $user->id, 'name' => $commitment->name],
                    [
                        'account_id' => $account->id,
                        'kind' => 'credit_card',
                        'outstanding_amount' => $commitment->current_balance,
                        'minimum_payment' => $minimum,
                        'due_date' => $commitment->next_due_date,
                        'last_four' => $commitment->reference_number,
                        'statement_day' => $commitment->statement_day,
                        'payment_grace_days' => $commitment->payment_grace_days,
                        'statement_balance' => $commitment->statement_balance,
                    ]
                );
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
                return [
                    'id' => $item->id,
                    'commitment_id' => $item->commitment_id,
                    'name' => $item->commitment?->name,
                    'type' => $item->commitment?->type ?? 'other',
                    'reference_number' => $item->commitment?->reference_number,
                    'institution' => $item->commitment?->institution,
                    'current_balance' => $item->commitment?->current_balance,
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
            }),
        ]);
    }

    public function update(Request $request, int $commitment): JsonResponse
    {
        $record = Commitment::query()->where('user_id', $request->user()->id)->whereKey($commitment)->firstOrFail();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', 'string', 'in:loan,credit_card,utility,subscription,insurance,rent,other'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'frequency' => ['required', 'string', 'in:once,weekly,biweekly,monthly,quarterly,yearly'],
            'next_due_date' => ['required', 'date'],
            'reference_number' => ['nullable', 'string', 'max:80'],
            'institution' => ['nullable', 'string', 'max:160'],
            'current_balance' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
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
        return [
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
            'category' => $item->category,
            'maturity_date' => $item->maturity_date?->toDateString(),
            'notes' => $item->notes,
        ];
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
