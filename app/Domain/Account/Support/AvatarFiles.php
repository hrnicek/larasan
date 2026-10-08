<?php

declare(strict_types=1);

namespace App\Domain\Account\Support;

use App\Domain\Account\Exceptions\AccountException;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final readonly class AvatarFiles
{
    /**
     * SVG is excluded because it can carry script.
     *
     * @var array<string, string>
     */
    public const array TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public const int MAX_KILOBYTES = 2048;

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('filesystems.avatars'));
    }

    /**
     * The extension comes from the sniffed content type, never the client filename, so an upload
     * cannot be served back as HTML.
     */
    public function store(User $user, UploadedFile $upload): string
    {
        $type = File::mimeType($upload->getRealPath());

        $extension = is_string($type) && isset(self::TYPES[$type])
            ? self::TYPES[$type]
            : throw AccountException::unsupportedAvatarType();

        $name = Str::uuid7()->toString().'.'.$extension;

        if ($this->disk()->putFileAs((string) $user->id, $upload, $name) === false) {
            throw AccountException::couldNotStoreAvatar();
        }

        return $user->id.'/'.$name;
    }

    public function discard(?string $path): void
    {
        if ($path !== null) {
            $this->disk()->delete($path);
        }
    }
}
