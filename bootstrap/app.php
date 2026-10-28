<?php

use App\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [SetLocaleFromHeader::class]);

        // API only: never redirect guests to a "login" page (the framework default, which fails
        // with a 500 because no such route exists); the 401 JSON below is rendered instead.
        $middleware->redirectGuestsTo(null);

        // auth:sanctum outranks the api group in the framework's priority list; without this,
        // 401 responses would ignore Accept-Language (research R6).
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: SetLocaleFromHeader::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // The framework's 401/403 messages are fixed English strings; translate them (research R5).
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => __('auth.unauthenticated')], 401);
            }
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => __('auth.forbidden')], 403);
            }
        });

        // The default report includes the SQL with its bound values, the driver's message (for
        // example "Duplicate entry '…'"), and a stack trace with arguments; any of these can hold
        // patient details. Log only placeholder SQL and error codes (research R7, approved D3).
        $exceptions->report(function (QueryException $e) {
            Log::error('Database query failed.', [
                'connection' => $e->getConnectionName(),
                'sql' => $e->getSql(),
                'sqlstate' => $e->errorInfo[0] ?? null,
                'driver_code' => $e->errorInfo[1] ?? null,
            ]);
        })->stop();
    })->create();
