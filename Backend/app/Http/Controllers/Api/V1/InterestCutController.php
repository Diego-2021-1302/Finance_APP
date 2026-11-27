<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\InterestCutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InterestCutController extends Controller
{
    public function index(Request $request, InterestCutService $interestCut): JsonResponse
    {
        return response()->json(['data' => $interestCut->calculate($request->user()->id)]);
    }
}