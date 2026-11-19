<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ImportedTransaction;
use App\Services\StatementImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatementImportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $transactions = ImportedTransaction::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('occurred_at')
            ->get();

        return response()->json(['data' => $transactions]);
    }

    public function store(Request $request, StatementImportService $service): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'exists:accounts,id'],
            'csv_content' => ['required', 'string'],
        ]);

        $account = Account::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($validated['account_id'])
            ->firstOrFail();

        $imported = $service->importCsv($request->user(), $account, $validated['csv_content']);

        return response()->json(['data' => $imported], 201);
    }

    public function updateStatus(Request $request, int $id, StatementImportService $service): JsonResponse
    {
        $transaction = ImportedTransaction::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($id)
            ->firstOrFail();

        $validated = $request->validate([
            'status' => ['required', 'in:confirmed,discarded,duplicate,pending'],
        ]);

        if ($validated['status'] === 'confirmed') {
            $updated = $service->confirm($transaction);
        } elseif ($validated['status'] === 'pending' || $validated['status'] === 'discarded') {
            if ($transaction->status === 'confirmed') {
                $service->revert($transaction);
            }
            $transaction->update(['status' => $validated['status']]);
            $updated = $transaction->refresh();
        } else {
            $transaction->update(['status' => $validated['status']]);
            $updated = $transaction->refresh();
        }

        return response()->json(['data' => $updated]);
    }
}
