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
 * Resolves the surface scheme and hands it to the root template, which writes it on <html>
 * before any stylesheet is parsed. A theme that arrived as page data instead would repaint after
 * hydration, and every full load would flash the other theme first.
 *
 * The signed-in row is the truth, so the choice follows the person to their next device. The
 * cookie is only the copy this browser keeps, and it answers on the screens that have no user —
 * sign-in, password reset, the moment after a logout — which would otherwise be the one place
 * the application forgets what it looks like.
 *
 * The cookie is refreshed *after* the controller has run and from the stored value, never from
 * the request: the response to a request that changes the theme has to leave with the new copy,
 * and a cookie must never be able to pick a theme that was not saved.
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

        // tryFrom rather than from: a scheme this version no longer ships must not leave the
        // page with no tokens at all.
        return (is_string($remembered) ? UiTheme::tryFrom($remembered) : null) ?? UiTheme::default();
    }
}
