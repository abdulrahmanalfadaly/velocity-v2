<?php

use App\Http\Middleware\ApiScope;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PersonalOnly;
use App\Http\Middleware\RequestId;
use App\Support\ApiErrors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

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
        $middleware->api(prepend: [RequestId::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // The API always answers in JSON: {"error": {"code", "message", "fields"?}}.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/*') ? ApiErrors::render($e) : null);
        // The screens: a wrong link, something deleted or a server problem shows Velocity's own page with a way back
        // (not the framework's bare "404"); a page left open too long just asks to try again.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();
            if ($request->is('api/*') || $request->expectsJson()) {
                return $response;
            }
            if ($status === 419) {
                return back()->with('toast', 'This page sat open too long. Try that again.');
            }
            if (in_array($status, [403, 404, 429, 503], true) || ($status === 500 && ! config('app.debug'))) {
                return Inertia::render('error', ['status' => $status])->toResponse($request)->setStatusCode($status);
            }

            return $response;
        });
    })->create();
