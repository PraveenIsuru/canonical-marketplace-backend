<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\EnsureUserHasStore;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PublicApiRoute;
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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        /*
         * The API is stateless and token authenticated. It deliberately does not get
         * the session, cookie, or CSRF middleware that the web group carries, because
         * public catalogue routes must not resolve a session at all.
         */

        $middleware->alias([
            'store' => EnsureUserHasStore::class,
            'admin' => EnsureUserIsAdmin::class,
            'public' => PublicApiRoute::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every API error leaves as { code, message, errors? }. See the renderer.
        $exceptions->render(
            fn (Throwable $e, Request $request) => ApiExceptionRenderer::render($e, $request),
        );
    })->create();
