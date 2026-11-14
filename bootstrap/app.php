<?php

use App\Http\Middleware\ApiScope;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PersonalOnly;
use App\Support\ApiErrors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->alias(['personal' => PersonalOnly::class, 'scope' => ApiScope::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // The API always answers in JSON: {"error": {"code", "message", "fields"?}}.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/*') ? ApiErrors::render($e) : null);
    })->create();
