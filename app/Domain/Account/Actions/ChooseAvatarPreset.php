<?php

declare(strict_types=1);

namespace App\Domain\Account\Actions;

use App\Domain\Account\Exceptions\AccountException;
use App\Domain\Account\Support\AvatarFiles;
use App\Domain\Account\Support\AvatarPresets;
use App\Models\User;

final readonly class ChooseAvatarPreset
{
    public function __construct(private AvatarFiles $files) {}

    public function handle(User $user, int $preset): void
    {
        if (! AvatarPresets::exists($preset)) {
            throw AccountException::unknownAvatarPreset();
        }

        $previous = $user->avatar_path;

        $user->avatar_preset = $preset;
        $user->avatar_path = null;
        $user->save();

        $this->files->discard($previous);
    }
}
