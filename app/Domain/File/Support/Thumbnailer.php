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

    private const QUALITY = 82;

    /**
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
