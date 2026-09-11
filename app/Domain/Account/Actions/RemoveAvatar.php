<?php

declare(strict_types=1);

namespace App\Domain\Account\Actions;

use App\Domain\Account\Support\AvatarFiles;
use App\Models\User;

/**
 * Go back to initials, and take an uploaded picture off the disk with it.
 */
final readonly class RemoveAvatar
{
    public function __construct(private AvatarFiles $files) {}

    public function handle(User $user): void
    {
        $previous = $user->avatar_path;

        $user->avatar_preset = null;
        $user->avatar_path = null;
        $user->save();

        $this->files->discard($previous);
    }
}
