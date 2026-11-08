<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\Authenticate;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum']]
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
        'auth' => Authenticate::class,
    ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(\App\Exceptions\GroupChallengeException::class);

        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Invalid data provided.',
                    'errors' => $e->errors(),
                ], 422);
            }

            return null;
        });

        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage() ?: 'Authentication is required.',
                ], 401);
            }

            return null;
        });

        $exceptions->render(function (AuthorizationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage() ?: 'You are not authorized to perform this action.',
                ], 403);
            }

            return null;
        });

        $exceptions->render(function (ModelNotFoundException $e, $request) {
            if ($request->is('api/*')) {
                $modelName = class_basename($e->getModel() ?? 'Resource');

                return response()->json([
                    'message' => "{$modelName} not found.",
                ], 404);
            }

            return null;
        });

        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Requested endpoint was not found.',
                ], 404);
            }

            return null;
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'HTTP method is not allowed for this endpoint.',
                ], 405);
            }

            return null;
        });

        $exceptions->render(function (QueryException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Invalid data provided for this operation.',
                ], 422);
            }

            return null;
        });

        $exceptions->render(function (HttpException $e, $request) {
            if ($request->is('api/*')) {
                $statusCode = $e->getStatusCode();
                $fallbackMessages = [
                    400 => 'Bad request.',
                    401 => 'Authentication is required.',
                    403 => 'You are not authorized to perform this action.',
                    404 => 'Resource not found.',
                    405 => 'HTTP method is not allowed for this endpoint.',
                    422 => 'Invalid data provided.',
                    429 => 'Too many requests. Please try again later.',
                ];

                return response()->json([
                    'message' => $e->getMessage() ?: ($fallbackMessages[$statusCode] ?? 'An error occurred.'),
                ], $statusCode, $e->getHeaders());
            }

            return null;
        });

        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->is('api/*')) {
                $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
                $message = config('app.debug')
                    ? ($e->getMessage() ?: 'Unexpected server error.')
                    : 'Unexpected server error. Please try again later.';

                return response()->json([
                    'message' => $message,
                ], $statusCode);
            }

            return null;
        });
    })->create();
