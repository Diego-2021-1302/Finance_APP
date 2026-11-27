<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordIncomeReceipt;
use App\Actions\ReversePayment;
use App\Actions\RecalculateCommitmentAmortization;
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
        $validated = $request->validated();
        $frequency = $validated['frequency'] ?? 'once';
        $validated['frequency'] = $frequency;
        if ($frequency !== 'once') {
            $validated['recurrence_key'] = $validated['recurrence_key'] ?? (string) \Illuminate\Support\Str::uuid();
            $validated['recurrence_day'] ??= (int) \Illuminate\Support\Carbon::parse($validated['expected_date'])->day;
        }
        $income = Income::query()->create($validated + ['user_id' => $request->user()->id]);
        return response()->json(['data' => $this->serialize($income)], 201);
    }

    public function update(StoreIncomeRequest $request, int $income): JsonResponse
    {
        $record = Income::query()->where('user_id', $request->user()->id)->whereKey($income)->firstOrFail();
        $record->update($request->validated());
        $record->load('payments');
        return response()->json(['data' => $this->serialize($record->refresh())]);
    }

    public function destroy(Request $request, int $income, ReversePayment $reversePayment, RecalculateCommitmentAmortization $amortization): JsonResponse
    {
        $record = Income::query()->where('user_id', $request->user()->id)->whereKey($income)->firstOrFail();
        $hasReceivedAmounts = $record->payments()->whereNull('reversed_at')->exists();

        if ($hasReceivedAmounts) {
            foreach ($record->payments()->whereNull('reversed_at')->get() as $payment) {
                $reversePayment->execute($request->user(), $payment, [
                    'reason' => 'Corrección o cancelación de ingreso recibido',
                    'idempotency_key' => 'income-cancel:' . $payment->id,
                ], $amortization);
            }
            $record->update(['notes' => trim(($record->notes ? $record->notes . "\n" : '') . 'Ingreso cancelado; recepciones revertidas y conservadas en historial.')]);
            return response()->json(['message' => 'Ingreso cancelado y recepciones revertidas; el historial se conserva']);
        }

        $record->delete();
        return response()->json(['message' => 'Ingreso previsto eliminado correctamente']);
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
