<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordExpense;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExpenseRequest;
use App\Models\Expense;
use App\Services\MoneyFormatter;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'category' => ['sometimes', 'string'],
            'account_id' => ['sometimes', 'integer'],
        ]);

        $query = Expense::query()->where('user_id', $request->user()->id)->with('account');

        if (isset($validated['from'])) $query->whereDate('occurred_at', '>=', $validated['from']);
        if (isset($validated['to'])) $query->whereDate('occurred_at', '<=', $validated['to']);
        if (isset($validated['category'])) $query->where('category', $validated['category']);
        if (isset($validated['account_id'])) $query->where('account_id', $validated['account_id']);

        $items = $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate(50);

        $data = $items->getCollection()->map(fn (Expense $expense) => [
            'id' => $expense->id,
            'account_id' => $expense->account_id,
            'account' => $expense->account?->name ?? '—',
            'category' => $expense->category,
            'amount' => $expense->amount,
            'occurred_at' => $expense->occurred_at->toDateString(),
            'description' => $expense->description,
            'reference_number' => $expense->reference_number,
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function store(StoreExpenseRequest $request, RecordExpense $action, MoneyFormatter $money): JsonResponse
    {
        $result = $action->execute($request->user(), $request->validated());
        $expense = $result['expense'];
        $account = $result['account'];

        $balance = (string) $account->transactions()->sum('amount');

        return response()->json([
            'data' => [
                'id' => $expense->id,
                'account_id' => $expense->account_id,
                'category' => $expense->category,
                'amount' => $expense->amount,
                'occurred_at' => $expense->occurred_at->toDateString(),
                'description' => $expense->description,
            ],
            'balance' => [
                'confirmed' => $money->dop($balance),
            ],
        ], $result['created'] ? 201 : 200);
    }

    public function update(Request $request, int $expense, TransactionService $service, MoneyFormatter $money): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'category' => ['required', 'string', 'max:64'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'occurred_at' => ['required', 'date'],
            'description' => ['required', 'string', 'max:500'],
            'reference_number' => ['nullable', 'string', 'max:80'],
        ]);

        $result = $service->updateExpense($request->user(), $expense, $validated);
        $balance = (string) $result['account']->transactions()->sum('amount');

        return response()->json([
            'data' => [
                'id' => $result['expense']->id,
                'account_id' => $result['expense']->account_id,
                'category' => $result['expense']->category,
                'amount' => $result['expense']->amount,
                'occurred_at' => $result['expense']->occurred_at->toDateString(),
                'description' => $result['expense']->description,
            ],
            'balance' => [
                'confirmed' => $money->dop($balance),
            ],
        ]);
    }

    public function destroy(Request $request, int $expense, TransactionService $service): JsonResponse
    {
        $service->deleteExpense($request->user(), $expense);
        return response()->json(['message' => 'Gasto eliminado correctamente']);
    }
}
