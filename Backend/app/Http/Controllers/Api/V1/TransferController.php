<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordTransfer;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransferRequest;
use App\Models\Transfer;
use App\Services\MoneyFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = Transfer::query()
            ->where('user_id', $request->user()->id)
            ->with(['sourceAccount', 'destinationAccount'])
            ->orderByDesc('transferred_at')
            ->orderByDesc('id')
            ->paginate(50);

        $data = $items->getCollection()->map(fn (Transfer $transfer) => [
            'id' => $transfer->id,
            'source_account' => $transfer->sourceAccount?->name ?? '—',
            'destination_account' => $transfer->destinationAccount?->name ?? '—',
            'amount' => $transfer->amount,
            'transferred_at' => $transfer->transferred_at->toDateString(),
            'notes' => $transfer->notes,
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

    public function store(StoreTransferRequest $request, RecordTransfer $action, MoneyFormatter $money): JsonResponse
    {
        $result = $action->execute($request->user(), $request->validated());
        $transfer = $result['transfer'];

        return response()->json([
            'data' => [
                'id' => $transfer->id,
                'source_account' => $transfer->sourceAccount?->name ?? '—',
                'destination_account' => $transfer->destinationAccount?->name ?? '—',
                'amount' => $transfer->amount,
                'transferred_at' => $transfer->transferred_at->toDateString(),
                'notes' => $transfer->notes,
            ],
        ], $result['created'] ? 201 : 200);
    }
}
