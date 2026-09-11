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

/**
 * Where uploaded faces are kept: a disk by name (ADR-0007), with no route of its own.
 *
 * The extension comes from the type the file was read as, never from the name it arrived with.
 * An avatar is drawn inline from this application's own origin, and a stored `face.html` served
 * with the type its extension claims is a page, not a picture.
 */
final readonly class AvatarFiles
{
    /**
     * No SVG: it is a document that can carry script, and a face is the one image every screen
     * draws.
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
     * The type is read from the bytes here rather than asked of the upload: an `UploadedFile`
     * handed in by a console command or a test may report the type its name suggests.
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
