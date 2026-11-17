<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::query()->create([
            'name' => $request->validated('name'),
            'email' => mb_strtolower($request->validated('email')),
            'password' => Hash::make($request->validated('password')),
        ]);
        return response()->json(['data' => ['user' => $this->userPayload($user)]], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', mb_strtolower($request->validated('email')))->first();
        if (!$user || !Hash::check($request->validated('password'), $user->password)) {
            return response()->json(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Las credenciales ingresadas no coinciden con nuestros registros.', 'details' => null]], 401);
        }
        $deviceId = $request->validated('device_id');
        $tokenName = 'finance-web:'.$deviceId;
        $user->tokens()->where('name', $tokenName)->delete();
        $token = $user->createToken($tokenName);
        return response()->json(['data' => ['user' => $this->userPayload($user), 'token' => $token->plainTextToken]]);
    }

    public function current(): JsonResponse
    {
        return response()->json(['data' => $this->userPayload(request()->user())]);
    }

    public function logout(): JsonResponse
    {
        request()->user()->currentAccessToken()?->delete();
        return response()->json(['data' => ['signed_out' => true]]);
    }

    private function userPayload(User $user): array
    {
        return ['id' => $user->getKey(), 'name' => $user->name, 'email' => $user->email];
    }
}
