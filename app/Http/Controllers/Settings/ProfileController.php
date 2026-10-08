<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Account\Actions\RemoveAvatar;
use App\Domain\Account\Support\AvatarFiles;
use App\Domain\Account\Support\AvatarPresets;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $this->actor($request);

        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'avatar' => [
                'preset' => $user->avatar_preset,
                'uploaded' => $user->avatar_path !== null,
            ],
            'avatarPresets' => array_map(
                fn (int $preset): array => ['id' => $preset, 'url' => AvatarPresets::url($preset)],
                AvatarPresets::all(),
            ),
            'avatarMaxKilobytes' => AvatarFiles::MAX_KILOBYTES,
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $this->actor($request);

        $user->fill([
            ...$request->validated(),
            'email' => Str::lower($request->string('email')->toString()),
        ]);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    public function destroy(ProfileDeleteRequest $request, RemoveAvatar $removeAvatar): RedirectResponse
    {
        $user = $this->actor($request);

        Auth::logout();

        $removeAvatar->handle($user);

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
