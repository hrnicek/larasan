<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Shared\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Middleware\HandleLocale;
use App\Http\Requests\Settings\UpdateLocaleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;

/** Open to guests too: the sign-in screens offer the switch before there is an account to save it to. */
class LocaleController extends Controller
{
    public function update(UpdateLocaleRequest $request): RedirectResponse
    {
        $locale = Locale::from($request->validated('locale'));
        $user = $request->user();

        if ($user !== null) {
            // Assigned directly because `locale` is excluded from mass assignment.
            $user->locale = $locale;
            $user->save();
        }

        Cookie::queue(Cookie::forever(HandleLocale::COOKIE, $locale->value));

        return back();
    }
}
