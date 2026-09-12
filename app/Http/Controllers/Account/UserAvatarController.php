<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Account\Queries\SharesAWorkspace;
use App\Domain\Account\Support\AvatarFiles;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A stranger gets a 404, so the account is not confirmed. The `v` query parameter changes with
 * every upload, which is what makes the immutable cache header safe.
 */
class UserAvatarController extends Controller
{
    public function __invoke(Request $request, User $user, SharesAWorkspace $sharesAWorkspace, AvatarFiles $files): StreamedResponse
    {
        if ($user->avatar_path === null || ! $sharesAWorkspace($this->actor($request), $user)) {
            abort(404);
        }

        $disk = $files->disk();

        if (! $disk->exists($user->avatar_path)) {
            abort(404);
        }

        return $disk->response($user->avatar_path, null, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
