<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FinancialTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date', 'after_or_equal:from'], 'account_id' => ['sometimes', 'integer', 'min:1'], 'q' => ['sometimes', 'string', 'max:120']]);
        $query = FinancialTransaction::query()->where('user_id', $request->user()->id)->with('account', 'payment');
        if (isset($filters['from'])) $query->whereDate('occurred_at', '>=', $filters['from']);
        if (isset($filters['to'])) $query->whereDate('occurred_at', '<=', $filters['to']);
        if (isset($filters['account_id'])) $query->where('account_id', $filters['account_id']);
        if (isset($filters['q'])) $query->where('description', 'like', '%'.$filters['q'].'%');
        $page = $query->latest('occurred_at')->paginate(50);
        return response()->json(['data' => $page->getCollection()->map(fn (FinancialTransaction $item) => ['id' => $item->id, 'movement' => $item->description, 'account' => $item->account?->name, 'amount' => $item->amount, 'recorded' => $item->occurred_at->toIso8601String(), 'kind' => $item->kind, 'payment_id' => $item->payment_id, 'reversed_at' => $item->payment?->reversed_at])->values(), 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }
}
