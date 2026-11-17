<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ReversePayment;
use App\Actions\RecalculateCommitmentAmortization;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReversePaymentRequest;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;

class PaymentReversalController extends Controller
{
    public function store(ReversePaymentRequest $request, int $payment, ReversePayment $action, RecalculateCommitmentAmortization $amortization): JsonResponse
    {
        $record = Payment::query()->where('user_id', $request->user()->id)->whereKey($payment)->firstOrFail();
        $reversed = $action->execute($request->user(), $record, $request->validated(), $amortization);
        return response()->json(['data' => ['id' => $reversed->id], 'reversal' => ['reason' => $reversed->reversal_reason, 'reversed_at' => $reversed->reversed_at]], 201);
    }
}
