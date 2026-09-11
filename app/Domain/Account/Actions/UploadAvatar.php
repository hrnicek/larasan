<?php

declare(strict_types=1);

namespace App\Domain\Account\Actions;

use App\Domain\Account\Support\AvatarFiles;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Wear a picture of your own.
 *
 * The object is written before the row and removed again if the row cannot be saved, so a
 * failure leaves either the old face or the new one — never a row pointing at nothing, and never
 * a stored picture nobody points at.
 */
final readonly class UploadAvatar
{
    public function __construct(private AvatarFiles $files) {}

    public function handle(User $user, UploadedFile $upload): void
    {
        $path = $this->files->store($user, $upload);

        $previous = $user->avatar_path;

        $user->avatar_preset = null;
        $user->avatar_path = $path;

        try {
            $user->save();
        } catch (Throwable $exception) {
            $this->files->discard($path);

            throw $exception;
        }

        $this->files->discard($previous);
    }
}
