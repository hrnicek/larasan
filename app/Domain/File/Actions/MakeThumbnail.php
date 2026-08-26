<?php

declare(strict_types=1);

namespace App\Domain\File\Actions;

use App\Domain\File\Models\File;
use App\Domain\File\Support\Thumbnailer;
use App\Domain\Shared\Enums\FileKind;
use Illuminate\Support\Facades\Storage;

/**
 * Give a file the small copy the screens draw.
 *
 * An Action rather than the body of a listener because three callers want it and only one of
 * them is an event: the listener that runs on upload, the backfill command for everything
 * attached before this existed, and any future re-derivation after the size changes.
 *
 * Every refusal is a `false` rather than an exception. There is nothing here a user did wrong —
 * the upload already succeeded — and a derivative that cannot be made is a heavier page, not a
 * failure anybody needs to be told about.
 */
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

        // Bytes this host cannot read as an image: a corrupt upload, or a format the extension
        // was built without.
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

    /**
     * Beside the original, under a directory of its own, so a workspace's objects are still one
     * prefix — which is what `files:sweep` and any future bulk export walk.
     */
    private function pathFor(File $file): string
    {
        $directory = dirname($file->path);
        $name = pathinfo($file->path, PATHINFO_FILENAME);

        return "{$directory}/thumbs/{$name}.webp";
    }
}
