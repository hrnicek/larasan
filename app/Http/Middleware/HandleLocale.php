<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Shared\Enums\Locale;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * The saved language wins, then the one this device chose before signing in, then the browser's.
 * The cookie is refreshed from the saved value so the sign-in page keeps the language after sign-out.
 */
class HandleLocale
{
    public const COOKIE = 'locale';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        App::setLocale($locale->value);
        CarbonImmutable::setLocale($locale->value);

        $response = $next($request);

        $user = $request->user();

        if ($user !== null) {
            Cookie::queue(Cookie::forever(self::COOKIE, $user->locale->value));
        }

        return $response;
    }

    private function resolve(Request $request): Locale
    {
        $user = $request->user();

        if ($user !== null) {
            return $user->locale;
        }

        $remembered = $request->cookie(self::COOKIE);

        if (is_string($remembered) && Locale::tryFrom($remembered) !== null) {
            return Locale::from($remembered);
        }

        $default = Locale::default();
        $offered = [$default->value, ...array_diff(Locale::values(), [$default->value])];

        return Locale::tryFrom((string) $request->getPreferredLanguage($offered)) ?? $default;
    }
}
