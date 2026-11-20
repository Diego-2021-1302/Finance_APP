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
        $record->update(['is_active' => false]);
        return response()->json(['data' => ['id' => $record->id, 'archived' => true]]);
    }

    public function update(Request $request, int $account): JsonResponse
    {
        $record = Account::query()->where('user_id', $request->user()->id)->whereKey($account)->firstOrFail();
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

        if ($record->transactions()->exists()) {
            $record->update(['is_active' => false]);
            return response()->json(['data' => ['id' => $record->id, 'deleted' => true, 'archived' => true]]);
        }

        $record->delete();
        return response()->json(['data' => ['id' => $account, 'deleted' => true]]);
    }

    public function reconcile(ReconcileAccountRequest $request, int $account, TransactionService $service): JsonResponse
    {
        $record = Account::query()->where('user_id', $request->user()->id)->whereKey($account)->firstOrFail();
        $result = $service->recordAdjustment($request->user(), $record, $request->validated());
        return response()->json(['data' => $this->serialize($result['account'])]);
    }

    private function serialize(Account $account): array
    {
        $balance = $account->transactions_sum_amount
            ?? $account->transactions()->sum('amount');
        return [
            'id' => $account->id,
            'name' => $account->name,
            'institution' => $account->institution,
            'type' => $account->type,
            'currency' => $account->currency,
            'last_four' => $account->last_four,
            'balance' => (string) $balance,
            'debt_id' => $account->debt_id,
        ];
    }
}
