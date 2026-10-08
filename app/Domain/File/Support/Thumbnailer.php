<?php

declare(strict_types=1);

namespace App\Domain\File\Support;

use GdImage;
use Imagick;
use ImagickException;

/** Imagick is preferred because it honours EXIF orientation; GD ignores it. */
final readonly class Thumbnailer
{
    /** Longest edge in pixels, about twice the widest board card for high-density screens. */
    public const MAX_EDGE = 480;

    /** Decoded pixels across every frame, about 50 megapixels; larger sources get no thumbnail. */
    public const MAX_SOURCE_PIXELS = 50_000_000;

    public const MAX_FRAMES = 100;

    private const QUALITY = 82;

    /**
     * @return array{bytes: string, width: int, height: int, sourceWidth: int, sourceHeight: int}|null
     */
    public function fromBlob(string $blob): ?array
    {
        $imagick = extension_loaded('imagick');

        if (! $this->isSmallEnoughToDecode($blob, $imagick)) {
            return null;
        }

        return $imagick
            ? $this->withImagick($blob)
            : $this->withGd($blob);
    }

    /** Headers only, since decoding is what exhausts the worker's memory. */
    private function isSmallEnoughToDecode(string $blob, bool $imagick): bool
    {
        $size = @getimagesizefromstring($blob);

        if ($size === false) {
            return false;
        }

        // GD decodes only the first frame; Imagick decodes every frame of an animation.
        $frames = $imagick ? $this->frameCount($blob) : 1;

        return $frames !== null
            && $frames <= self::MAX_FRAMES
            && $size[0] * $size[1] * $frames <= self::MAX_SOURCE_PIXELS;
    }

    private function frameCount(string $blob): ?int
    {
        try {
            $probe = new Imagick;
            $probe->pingImageBlob($blob);

            $frames = $probe->getNumberImages();

            $probe->clear();

            return $frames;
        } catch (ImagickException) {
            return null;
        }
    }

    /**
     * @return array{bytes: string, width: int, height: int, sourceWidth: int, sourceHeight: int}|null
     */
    private function withImagick(string $blob): ?array
    {
        try {
            $image = new Imagick;
            $image->readImageBlob($blob);

            // Animated images are thumbnailed from their first frame.
            if ($image->getNumberImages() > 1) {
                $image = $image->coalesceImages();
                $image->setFirstIterator();
            }

            $this->orient($image);

            $sourceWidth = $image->getImageWidth();
            $sourceHeight = $image->getImageHeight();

            if (max($sourceWidth, $sourceHeight) > self::MAX_EDGE) {
                $image->thumbnailImage(self::MAX_EDGE, self::MAX_EDGE, bestfit: true);
            }

            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(self::QUALITY);

            // Strips EXIF metadata such as location, which must not be served.
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
            return null;
        }

        // Without these a transparent PNG is resampled onto black.
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);

        imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        ob_start();
        imagewebp($thumbnail, null, self::QUALITY);
        $bytes = (string) ob_get_clean();

        return $bytes === '' ? null : [
            'bytes' => $bytes,
            'width' => $width,
            'height' => $height,
            'sourceWidth' => $sourceWidth,
            'sourceHeight' => $sourceHeight,
        ];
    }
}
