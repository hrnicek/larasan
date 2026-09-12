<?php

declare(strict_types=1);

namespace App\Domain\File\Actions;

use App\Domain\File\Models\File;
use App\Domain\File\Support\Thumbnailer;
use App\Domain\Shared\Enums\FileKind;
use Illuminate\Support\Facades\Storage;

final readonly class MakeThumbnail
{
    public function __construct(private Thumbnailer $thumbnailer) {}

    public function handle(File $file, bool $force = false): bool
    {
        if (! $force && $file->thumbnail() !== null) {
            return false;
        }

        if (FileKind::fromMime($file->mime_type, $file->extension) !== FileKind::Image) {
            return false;
        }

        $disk = Storage::disk($file->disk);

        if (! $disk->exists($file->path)) {
            return false;
        }

        $blob = $disk->get($file->path);

        if ($blob === null) {
            return false;
        }

        $thumbnail = $this->thumbnailer->fromBlob($blob);

        if ($thumbnail === null) {
            return false;
        }

        $path = $this->pathFor($file);

        $disk->put($path, $thumbnail['bytes']);

        $file->metadata = [
            ...$file->metadata,
            'width' => $thumbnail['sourceWidth'],
            'height' => $thumbnail['sourceHeight'],
            'thumb' => [
                'path' => $path,
                'width' => $thumbnail['width'],
                'height' => $thumbnail['height'],
            ],
        ];

        return $file->save();
    }

    private function pathFor(File $file): string
    {
        $directory = dirname($file->path);
        $name = pathinfo($file->path, PATHINFO_FILENAME);

        return "{$directory}/thumbs/{$name}.webp";
    }
}
