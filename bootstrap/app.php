<?php

use App\Domain\Shared\Exceptions\DomainRefusal;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
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
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            ResolveCurrentWorkspace::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * A domain refusal is a "no", not a crash. Actions throw rather than calling
         * `abort()` because only the transport layer knows what a refusal should look like
         * — and until this existed, the refusals a FormRequest cannot pre-check (an
         * assignee who cannot reach the task, a slot that closed between the read and the
         * write) reached the browser as a 500.
         *
         * The message is the sentence the named constructor wrote, which is why those
         * constructors write sentences.
         */
        $exceptions->render(function (DomainRefusal $refusal, Request $request) {
            $message = $refusal instanceof Throwable ? $refusal->getMessage() : '';

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $message], SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return back()->withErrors(['refusal' => $message]);
        });
    })->create();
