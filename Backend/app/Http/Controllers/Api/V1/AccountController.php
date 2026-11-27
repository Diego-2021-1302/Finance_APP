<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReconcileAccountRequest;
use App\Http\Requests\StoreAccountRequest;
use App\Models\Account;
use App\Models\FinancialTransaction;
use App\Services\TransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function store(StoreAccountRequest $request): JsonResponse
    {
        $data = $request->validated();
        $account = DB::transaction(function () use ($request, $data) {
            $account = Account::query()->create([
                'user_id' => $request->user()->id,
                'name' => $data['name'],
                'institution' => $data['institution'] ?? null,
                'type' => $data['type'],
                'currency' => $data['currency'],
            ]);
            if (bccomp($data['opening_balance'], '0.00', 2) !== 0) {
                FinancialTransaction::query()->create([
                    'user_id' => $request->user()->id,
                    'account_id' => $account->id,
                    'description' => 'Opening balance',
                    'amount' => $data['opening_balance'],
                    'occurred_at' => now(),
                    'kind' => 'account_opening',
                ]);
            }
            return $account;
        });

        return response()->json(['data' => $this->serialize($account)], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $data = Account::query()
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->withSum('transactions', 'amount')
            ->orderBy('name')
            ->get()
            ->map(fn (Account $account) => $this->serialize($account));

        return response()->json(['data' => $data]);
    }

    public function archive(Request $request, int $account): JsonResponse
    {
        $record = Account::query()->where('user_id', $request->user()->id)->whereKey($account)->firstOrFail();
        $this->ensureAccountIsNotCard($record);
        $record->update(['is_active' => false]);
        return response()->json(['data' => ['id' => $record->id, 'archived' => true]]);
    }

    public function update(Request $request, int $account): JsonResponse
    {
        $record = Account::query()->where('user_id', $request->user()->id)->whereKey($account)->firstOrFail();
        $this->ensureAccountIsNotCard($record);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'institution' => ['nullable', 'string', 'max:160'],
            'type' => ['required', 'string', 'in:checking,savings,cash,credit_card,other'],
        ]);

        $record->update([
            'name' => $validated['name'],
            'institution' => $validated['institution'] ?? null,
            'type' => $validated['type'],
        ]);

        return response()->json(['data' => $this->serialize($record->refresh())]);
    }

    public function destroy(Request $request, int $account): JsonResponse
    {
        $record = Account::query()->where('user_id', $request->user()->id)->whereKey($account)->firstOrFail();
        $this->ensureAccountIsNotCard($record);

        if ($record->transactions()->exists()) {
            $record->update(['is_active' => false]);
            return response()->json(['data' => ['id' => $record->id, 'deleted' => true, 'archived' => true]]);
        }

        $record->delete();
        return response()->json(['data' => ['id' => $account, 'deleted' => true]]);
    }

    public function deposit(Request $request, int $account): JsonResponse
    {
        $record = Account::query()->where('user_id', $request->user()->id)->whereKey($account)->firstOrFail();
        $this->ensureAccountIsNotCard($record);
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['required', 'string', 'max:255'],
            'occurred_at' => ['required', 'date'],
        ]);

        $transaction = DB::transaction(function () use ($request, $record, $validated) {
            $record->lockForUpdate()->first();
            return FinancialTransaction::query()->create([
                'user_id' => $request->user()->id,
                'account_id' => $record->id,
                'description' => $validated['description'],
                'amount' => $validated['amount'],
                'occurred_at' => $validated['occurred_at'],
                'kind' => 'account_deposit',
            ]);
        });

        return response()->json([
            'data' => $this->serialize($record->refresh()->loadSum('transactions', 'amount')),
            'transaction_id' => $transaction->id,
        ], 201);
    }

    public function reconcile(ReconcileAccountRequest $request, int $account, TransactionService $service): JsonResponse
    {
        $record = Account::query()->where('user_id', $request->user()->id)->whereKey($account)->firstOrFail();
        $this->ensureAccountIsNotCard($record);
        $result = $service->recordAdjustment($request->user(), $record, $request->validated());
        return response()->json(['data' => $this->serialize($result['account'])]);
    }

    private function ensureAccountIsNotCard(Account $account): void
    {
        if ($account->type === 'credit_card' || $account->commitment_id !== null || $account->debt_id !== null) {
            throw ValidationException::withMessages([
                'account' => ['Las tarjetas vinculadas se administran desde Pagos.'],
            ]);
        }
    }

    private function serialize(Account $account): array
    {
        $balance = $account->transactions_sum_amount
            ?? $account->transactions()->sum('amount');

        $outstandingAmount = null;
        $availableCapacity = null;
        if ($account->type === 'credit_card') {
            $debt = \App\Models\Debt::query()
                ->where('user_id', $account->user_id)
                ->where(function ($query) use ($account) {
                    $query->where('id', $account->debt_id)
                        ->orWhere('account_id', $account->id);
                })
                ->first();

            $approved = (string) ($account->approved_credit_limit ?? $account->credit_limit ?? '0.00');
            $overdraft = (string) ($account->authorized_overdraft_limit ?? '0.00');
            $outstandingAmount = (string) ($debt?->outstanding_amount ?? '0.00');
            $availableCapacity = bcsub(bcadd($approved, $overdraft, 2), $outstandingAmount, 2);
            if (bccomp($availableCapacity, '0.00', 2) < 0) {
                $availableCapacity = '0.00';
            }
            $balance = $availableCapacity;
        }

        return [
            'id' => $account->id,
            'name' => $account->name,
            'institution' => $account->institution,
            'type' => $account->type,
            'currency' => $account->currency,
            'last_four' => $account->last_four,
            'balance' => (string) $balance,
            'debt_id' => $account->debt_id,
            'approved_credit_limit' => $account->approved_credit_limit,
            'authorized_overdraft_limit' => $account->authorized_overdraft_limit,
            'outstanding_amount' => $outstandingAmount,
            'available_credit_capacity' => $availableCapacity,
        ];
    }
}
