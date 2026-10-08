<?php

declare(strict_types=1);

namespace App\Domain\Account\Actions;

use App\Domain\Account\Support\AvatarFiles;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Throwable;

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
