<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordIncomeReceipt;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIncomeRequest;
use App\Http\Requests\StoreReceiptRequest;
use App\Models\Account;
use App\Models\Income;
use App\Services\MoneyFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncomeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date', 'after_or_equal:from'], 'status' => ['sometimes', 'in:expected,partially_received,received']]);
        $query = Income::query()->where('user_id', $request->user()->id)->with('payments');
        if (isset($validated['from'])) $query->whereDate('expected_date', '>=', $validated['from']);
        if (isset($validated['to'])) $query->whereDate('expected_date', '<=', $validated['to']);
        if (isset($validated['status'])) {
            $status = $validated['status'];
            if ($status === 'expected') {
                $query->whereDoesntHave('payments', fn($q) => $q->whereNull('reversed_at'));
            } elseif ($status === 'received') {
                $query->whereRaw('(select coalesce(sum(amount), 0) from payments where payments.income_id = incomes.id and payments.reversed_at is null) >= incomes.amount');
            } elseif ($status === 'partially_received') {
                $query->whereRaw('(select coalesce(sum(amount), 0) from payments where payments.income_id = incomes.id and payments.reversed_at is null) > 0')
                      ->whereRaw('(select coalesce(sum(amount), 0) from payments where payments.income_id = incomes.id and payments.reversed_at is null) < incomes.amount');
            }
        }
        $items = $query->orderBy('expected_date')->paginate(50);
        $data = $items->getCollection()->map(fn (Income $income) => $this->serialize($income));
        return response()->json(['data' => $data, 'meta' => ['current_page' => $items->currentPage(), 'per_page' => $items->perPage(), 'total' => $items->total()]]);
    }

    public function store(StoreIncomeRequest $request): JsonResponse
    {
        $income = Income::query()->create($request->validated() + ['user_id' => $request->user()->id]);
        return response()->json(['data' => $this->serialize($income)], 201);
    }

    public function receipt(StoreReceiptRequest $request, int $income, RecordIncomeReceipt $action, MoneyFormatter $money): JsonResponse
    {
        $record = Income::query()->where('user_id', $request->user()->id)->whereKey($income)->firstOrFail();
        $account = Account::query()->where('user_id', $request->user()->id)->whereKey($request->validated('account_id'))->firstOrFail();
        $payment = $action->execute($request->user(), $record, $request->validated());
        $balance = (string) $account->transactions()->sum('amount');
        return response()->json(['data' => ['id' => $payment->id, 'amount' => $payment->amount, 'received_at' => $payment->paid_at, 'account_id' => $account->id], 'balance' => ['confirmed' => $money->dop($balance)]], 201);
    }

    private function serialize(Income $income): array
    {
        $received = $income->payments->whereNull('reversed_at')->sum('amount');
        $status = bccomp((string) $received, '0', 2) === 0 ? 'expected' : (bccomp((string) $received, (string) $income->amount, 2) >= 0 ? 'received' : 'partially_received');
        return ['id' => $income->id, 'source' => $income->source, 'amount' => $income->amount, 'expected_date' => $income->expected_date->toDateString(), 'received_amount' => (string) $received, 'status' => $status];
    }
}
