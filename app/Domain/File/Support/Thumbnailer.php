<?php

declare(strict_types=1);

namespace App\Domain\File\Support;

use GdImage;
use Imagick;
use ImagickException;

/**
 * Make a small picture out of a large one.
 *
 * Deliberately not a package. Both extensions this can use are already installed, the operation
 * is one resize and one encode, and an image library would arrive with a service provider, a
 * facade and a driver abstraction to do it.
 *
 * Imagick is preferred where it exists because it reads a photograph's orientation and GD does
 * not: a picture taken on a telephone is stored landscape with a tag saying which way is up, and
 * a thumbnail that ignores the tag is sideways. GD is the fallback rather than the choice.
 *
 * WebP for the output because the derivative is ours — nobody downloads it, every browser this
 * application supports draws it, and it is roughly a third of the JPEG.
 */
final readonly class Thumbnailer
{
    /**
     * The longer edge of the derivative. One size, not a set: 480 is twice the widest a board
     * card draws and three times a grid tile, so it is sharp on a retina screen at both, and a
     * second size would double the storage to serve pictures nobody looks at closely.
     */
    public const MAX_EDGE = 480;

    private const QUALITY = 82;

    /**
     * Null where the bytes are not an image this can read — a corrupt upload, or a type the
     * extension was built without. The caller records nothing rather than failing the upload,
     * which happened some time ago and succeeded.
     *
     * @return array{bytes: string, width: int, height: int, sourceWidth: int, sourceHeight: int}|null
     */
    public function fromBlob(string $blob): ?array
    {
        return extension_loaded('imagick')
            ? $this->withImagick($blob)
            : $this->withGd($blob);
    }

    /**
     * @return array{bytes: string, width: int, height: int, sourceWidth: int, sourceHeight: int}|null
     */
    private function withImagick(string $blob): ?array
    {
        try {
            $image = new Imagick;
            $image->readImageBlob($blob);

            // An animated GIF is a stack of frames. The thumbnail is the first one — a moving
            // tile in a grid of twenty is not a feature anybody asked for.
            if ($image->getNumberImages() > 1) {
                $image = $image->coalesceImages();
                $image->setFirstIterator();
            }

            $this->orient($image);

            $sourceWidth = $image->getImageWidth();
            $sourceHeight = $image->getImageHeight();

            if (max($sourceWidth, $sourceHeight) > self::MAX_EDGE) {
                // `bestfit` keeps the aspect ratio inside the box rather than filling it, and
                // the box is square so the longer edge is what lands on MAX_EDGE.
                $image->thumbnailImage(self::MAX_EDGE, self::MAX_EDGE, bestfit: true);
            }

            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(self::QUALITY);

            // Location, camera serial, and the rest of what a photograph carries. None of it is
            // ours to serve, and it is larger than the picture at this size.
            $image->stripImage();

            $bytes = $image->getImageBlob();
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();

            $image->clear();

            return [
                'bytes' => $bytes,
                'width' => $width,
                'height' => $height,
                'sourceWidth' => $sourceWidth,
                'sourceHeight' => $sourceHeight,
            ];
        } catch (ImagickException) {
            return null;
        }
    }

    /**
     * Which way up the photograph was taken, applied to the pixels so the tag can be dropped.
     */
    private function orient(Imagick $image): void
    {
        $transparent = 'rgba(0, 0, 0, 0)';

        match ($image->getImageOrientation()) {
            Imagick::ORIENTATION_TOPRIGHT => $image->flopImage(),
            Imagick::ORIENTATION_BOTTOMRIGHT => $image->rotateImage($transparent, 180),
            Imagick::ORIENTATION_BOTTOMLEFT => $image->flipImage(),
            Imagick::ORIENTATION_LEFTTOP => $this->transpose($image, 90),
            Imagick::ORIENTATION_RIGHTTOP => $image->rotateImage($transparent, 90),
            Imagick::ORIENTATION_RIGHTBOTTOM => $this->transpose($image, 270),
            Imagick::ORIENTATION_LEFTBOTTOM => $image->rotateImage($transparent, 270),
            default => true,
        };

        $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
    }

    private function transpose(Imagick $image, float $degrees): bool
    {
        $image->flopImage();

        return $image->rotateImage('rgba(0, 0, 0, 0)', $degrees);
    }

    /**
     * The fallback. GD reads no orientation tag, so a photograph from a telephone can come out
     * on its side here — which is the reason Imagick is asked first rather than a reason to add
     * an EXIF parser to a code path that only runs where Imagick is absent.
     *
     * @return array{bytes: string, width: int, height: int, sourceWidth: int, sourceHeight: int}|null
     */
    private function withGd(string $blob): ?array
    {
        $source = @imagecreatefromstring($blob);

        if (! $source instanceof GdImage) {
            return null;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        $scale = min(1, self::MAX_EDGE / max($sourceWidth, $sourceHeight));
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));

        $thumbnail = imagecreatetruecolor($width, $height);

        if (! $thumbnail instanceof GdImage) {
            imagedestroy($source);

            return null;
        }

        // Without these a transparent PNG is resampled onto black.
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);

        imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        ob_start();
        imagewebp($thumbnail, null, self::QUALITY);
        $bytes = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($thumbnail);

        return $bytes === '' ? null : [
            'bytes' => $bytes,
            'width' => $width,
            'height' => $height,
            'sourceWidth' => $sourceWidth,
            'sourceHeight' => $sourceHeight,
        ];
    }
}
