<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

use Illuminate\Support\Str;

enum FileKind: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Pdf = 'pdf';
    case Document = 'document';
    case Spreadsheet = 'spreadsheet';
    case Presentation = 'presentation';
    case Archive = 'archive';
    case Text = 'text';
    case Other = 'other';

    public static function fromMime(string $mimeType, string $extension = ''): self
    {
        $mime = Str::lower($mimeType);

        return match (true) {
            str_starts_with($mime, 'image/') => self::Image,
            str_starts_with($mime, 'video/') => self::Video,
            str_starts_with($mime, 'audio/') => self::Audio,
            $mime === 'application/pdf' => self::Pdf,
            self::says($mime, ['wordprocessingml', 'msword', 'opendocument.text']) => self::Document,
            self::says($mime, ['spreadsheetml', 'ms-excel', 'opendocument.spreadsheet']) => self::Spreadsheet,
            self::says($mime, ['presentationml', 'ms-powerpoint', 'opendocument.presentation']) => self::Presentation,
            self::says($mime, ['zip', 'tar', 'gzip', 'x-7z', 'x-rar']) => self::Archive,
            str_starts_with($mime, 'text/') => self::Text,
            default => self::fromExtension($extension),
        };
    }

    private static function fromExtension(string $extension): self
    {
        return match (Str::lower($extension)) {
            'doc', 'docx', 'odt', 'rtf', 'pages' => self::Document,
            'xls', 'xlsx', 'ods', 'csv' => self::Spreadsheet,
            'ppt', 'pptx', 'odp', 'key' => self::Presentation,
            'zip', 'rar', 'gz', 'tar', '7z' => self::Archive,
            'pdf' => self::Pdf,
            'md', 'txt', 'log' => self::Text,
            default => self::Other,
        };
    }

    /**
     * @param  list<string>  $needles
     */
    private static function says(string $mime, array $needles): bool
    {
        return Str::contains($mime, $needles);
    }
}
