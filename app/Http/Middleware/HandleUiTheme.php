<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Shared\Enums\UiTheme;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares the theme with the root template so <html> carries it before first paint. The cookie is
 * refreshed from the saved value after the controller runs, so it can never select an unsaved theme.
 */
class HandleUiTheme
{
    public const COOKIE = 'ui_theme';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        View::share('uiTheme', $this->resolve($request)->value);

        $response = $next($request);

        $user = $request->user();

        if ($user !== null) {
            Cookie::queue(Cookie::forever(self::COOKIE, $user->ui_theme->value));
        }

        return $response;
    }

    private function resolve(Request $request): UiTheme
    {
        $user = $request->user();

        if ($user !== null) {
            return $user->ui_theme;
        }

        $remembered = $request->cookie(self::COOKIE);

        // tryFrom, so a cookie naming a retired theme falls back to the default.
        return (is_string($remembered) ? UiTheme::tryFrom($remembered) : null) ?? UiTheme::default();
    }
}
