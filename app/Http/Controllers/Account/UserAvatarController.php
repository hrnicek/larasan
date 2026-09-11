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
 * An uploaded face, drawn for somebody allowed to see it (ADR-0007: a stored path is never a
 * capability). A 404 rather than a 403 for a stranger, so the answer does not confirm that the
 * account exists.
 *
 * The URL carries the stored file's name as `v`, so a new picture is a new address and the old
 * one can be cached for as long as the browser likes.
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
