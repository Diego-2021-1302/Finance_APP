<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Services\ChatAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $messages = ChatMessage::where('user_id', $request->user()->id)
                ->orderBy('id', 'asc')
                ->get();

            return response()->json(['data' => $messages]);
        } catch (\Throwable $e) {
            Log::error('Error loading chat messages: '.$e->getMessage());
            return response()->json(['data' => []]);
        }
    }

    public function store(Request $request, ChatAssistantService $service): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        try {
            $assistantMessage = $service->processMessage($request->user(), $validated['message']);

            return response()->json([
                'data' => $assistantMessage,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Error processing chat message: '.$e->getMessage());
            return response()->json([
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'No se pudo procesar el mensaje en este momento. Inténtalo de nuevo.',
                ],
            ], 500);
        }
    }

    public function clear(Request $request): JsonResponse
    {
        try {
            ChatMessage::where('user_id', $request->user()->id)->delete();
            return response()->json(['data' => ['cleared' => true]]);
        } catch (\Throwable $e) {
            Log::error('Error clearing chat messages: '.$e->getMessage());
            return response()->json(['data' => ['cleared' => false]]);
        }
    }
}
