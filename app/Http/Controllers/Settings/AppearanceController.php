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

        // Assigned directly because `ui_theme` is excluded from mass assignment.
        $user->ui_theme = UiTheme::from($request->validated('ui_theme'));
        $user->save();

        return back();
    }
}
