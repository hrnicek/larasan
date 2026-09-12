<?php

declare(strict_types=1);

namespace App\Domain\Shared\Html;

final readonly class LinkHref
{
    /**
     * @var list<string>
     */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    public static function sanitize(string $href): ?string
    {
        // Browsers drop tab and newline anywhere in a URL and trim control characters, so `java\tscript:` still runs.
        $href = trim((string) preg_replace('/[\x00-\x1F]+/', '', $href), ' ');

        if ($href === '') {
            return null;
        }

        // Browsers read a backslash as a slash, so `/\host` is protocol-relative too.
        if (preg_match('#^[/\\\\]{2}#', $href) === 1) {
            return null;
        }

        if (! str_contains($href, ':') || in_array($href[0], ['/', '?', '#'], true)) {
            return $href;
        }

        $scheme = strtolower((string) strstr($href, ':', true));

        return in_array($scheme, self::ALLOWED_SCHEMES, true) ? $href : null;
    }
}
