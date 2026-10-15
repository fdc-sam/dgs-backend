<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\EnsureAdminApp;
use App\Http\Middleware\EnsureCustomer;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'customer' => EnsureCustomer::class,
            'admin' => EnsureAdminAccess::class,
            'admin.app' => EnsureAdminApp::class,
        ]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Expected business outcomes (409 slot taken, etc.) are not errors worth logging.
        $exceptions->dontReport(ApiException::class);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $json = fn (int $status, string $code, string $message, array $extra = []) => response()->json(
            ['success' => false, 'message' => $message, 'code' => $code, ...$extra],
            $status,
        );

        $exceptions->render(function (Throwable $e, Request $request) use ($json) {
            if (! $request->is('api/*')) {
                return null; // default web handling
            }

            return match (true) {
                $e instanceof ApiException => $json($e->status, $e->errorCode, $e->getMessage(), $e->errors ? ['errors' => $e->errors] : []),
                $e instanceof ValidationException => $json(422, 'VALIDATION_ERROR', $e->getMessage(), ['errors' => $e->errors()]),
                $e instanceof AuthenticationException => $json(401, 'UNAUTHENTICATED', 'Your session has expired. Please sign in again.'),
                $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => $json(403, 'FORBIDDEN', $e->getMessage() && $e->getMessage() !== 'This action is unauthorized.' ? $e->getMessage() : 'You are not allowed to perform this action.'),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => $json(404, 'NOT_FOUND', 'The requested resource was not found.'),
                $e instanceof ThrottleRequestsException => $json(429, 'TOO_MANY_REQUESTS', 'Too many requests. Please slow down and try again shortly.'),
                $e instanceof HttpExceptionInterface => $json($e->getStatusCode(), 'HTTP_'.$e->getStatusCode(), $e->getMessage() ?: 'Request failed.'),
                default => $json(500, 'SERVER_ERROR', config('app.debug') ? $e->getMessage() : 'Something went wrong. Please try again later.'),
            };
        });
    })->create();
