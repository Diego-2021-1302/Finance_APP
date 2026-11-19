<?php

namespace App\Http\Controllers\Api\V1;

class PaymentPriorityController
{
    public function index(\Illuminate\Http\Request $request, \App\Services\PaymentPriorityService $service): \Illuminate\Http\JsonResponse
    {
        $priorities = $service->priorities($request->user());
        return response()->json(['data' => $priorities]);
    }
}
