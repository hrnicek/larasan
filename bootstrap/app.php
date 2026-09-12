<?php

use App\Domain\Shared\Exceptions\DomainRefusal;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HandleUiTheme;
use App\Http\Middleware\ResolveCurrentWorkspace;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // The default guards broadcasting/auth with `web` alone, which would let unverified accounts subscribe.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        attributes: ['middleware' => ['web', 'auth', 'verified']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleUiTheme::class,
            ResolveCurrentWorkspace::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Refusal messages are shown to the user verbatim.
        $exceptions->render(function (DomainRefusal $refusal, Request $request) {
            $message = $refusal instanceof Throwable ? $refusal->getMessage() : '';

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $message], SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return back()->withErrors(['refusal' => $message]);
        });
    })->create();
