<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (\Throwable $exception, \Illuminate\Http\Request $request) {
            if (!$request->is('api/*')) return null;

            if ($exception instanceof ValidationException) {
                return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'The submitted data is invalid.', 'details' => $exception->errors()]], 422);
            }
            if ($exception instanceof AuthenticationException) {
                return response()->json(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication is required.', 'details' => null]], 401);
            }
            if ($exception instanceof AuthorizationException) {
                return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'You are not allowed to perform this action.', 'details' => null]], 403);
            }
            if ($exception instanceof ModelNotFoundException) {
                return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'The requested record was not found.', 'details' => null]], 404);
            }
            if ($exception instanceof HttpExceptionInterface) {
                $status = $exception->getStatusCode();
                $code = $status === 404 ? 'NOT_FOUND' : ($status === 409 ? 'CONFLICT' : 'INTERNAL_ERROR');
                return response()->json(['error' => ['code' => $code, 'message' => $status >= 500 ? 'An unexpected error occurred.' : $exception->getMessage(), 'details' => null]], $status);
            }
            report($exception);
            return response()->json(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred.', 'details' => null]], 500);
        });
    })->create();
