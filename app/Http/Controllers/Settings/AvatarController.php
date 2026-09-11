<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Account\Actions\ChooseAvatarPreset;
use App\Domain\Account\Actions\RemoveAvatar;
use App\Domain\Account\Actions\UploadAvatar;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ChooseAvatarPresetRequest;
use App\Http\Requests\Settings\UploadAvatarRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The signed-in person's own face. There is no other person to authorize against: every route
 * here acts on the actor, and none takes an id.
 */
class AvatarController extends Controller
{
    public function update(ChooseAvatarPresetRequest $request, ChooseAvatarPreset $choose): RedirectResponse
    {
        $choose->handle($this->actor($request), $request->integer('preset'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile picture updated.')]);

        return back();
    }

    public function store(UploadAvatarRequest $request, UploadAvatar $upload): RedirectResponse
    {
        $upload->handle($this->actor($request), $request->avatar());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile picture updated.')]);

        return back();
    }

    public function destroy(Request $request, RemoveAvatar $remove): RedirectResponse
    {
        $remove->handle($this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile picture removed.')]);

        return back();
    }
}
