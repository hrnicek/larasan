<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Shared\Enums\UiTheme;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateAppearanceRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Two controls on one screen, because they answer the same question from opposite ends.
 * Appearance — light, dark or whatever the device prefers — stays a cookie, since how bright a
 * screen should be depends on the room it is in. The theme is stored on the person (ADR-0019).
 */
class AppearanceController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Appearance', [
            'uiTheme' => $this->actor($request)->ui_theme->value,
            'uiThemes' => UiTheme::options(),
        ]);
    }

    public function update(UpdateAppearanceRequest $request): RedirectResponse
    {
        $user = $this->actor($request);

        // Assigned rather than mass updated: `ui_theme` is outside the model's #[Fillable] on
        // purpose, so no other update path can pick it up out of a request payload.
        $user->ui_theme = UiTheme::from($request->validated('ui_theme'));
        $user->save();

        return back();
    }
}
