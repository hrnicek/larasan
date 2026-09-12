<?php

declare(strict_types=1);

namespace App\Domain\Page\Content;

use App\Domain\Page\Exceptions\PageException;

/**
 * Allowlist sanitizer for stored ProseMirror JSON, so page content never reaches a browser as markup. See ADR-0017.
 */
final readonly class PageDocument
{
    public const MAX_DEPTH = 12;

    public const MAX_NODES = 5000;

    public const EXCERPT_LENGTH = 200;

    /**
     * @var array<string, list<string>>
     */
    private const NODES = [
        'doc' => [],
        'paragraph' => [],
        'heading' => ['level'],
        'text' => [],
        'hardBreak' => [],
        'bulletList' => [],
        'orderedList' => ['start'],
        'listItem' => [],
        'taskList' => [],
        'taskItem' => ['checked'],
        'blockquote' => [],
        'codeBlock' => ['language'],
        'horizontalRule' => [],
        'table' => [],
        'tableRow' => [],
        'tableHeader' => ['colspan', 'rowspan', 'colwidth'],
        'tableCell' => ['colspan', 'rowspan', 'colwidth'],
    ];

    /**
     * `target` and `rel` are set where a link is rendered, never taken from the document.
     *
     * @var array<string, list<string>>
     */
    private const MARKS = [
        'bold' => [],
        'italic' => [],
        'underline' => [],
        'strike' => [],
        'code' => [],
        'link' => ['href'],
    ];

    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    private const MAX_HEADING_LEVEL = 3;

    /**
     * @return array<string, mixed>
     */
    public static function empty(): array
    {
        return ['type' => 'doc', 'content' => []];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PageException
     */
    public static function sanitize(mixed $document): array
    {
        if (! is_array($document) || ($document['type'] ?? null) !== 'doc') {
            throw PageException::notADocument();
        }

        $budget = self::MAX_NODES;

        return [
            'type' => 'doc',
            'content' => self::children($document['content'] ?? [], 1, $budget),
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public static function toPlainText(array $document): string
    {
        $text = self::textOf($document);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public static function excerpt(array $document): ?string
    {
        $text = self::toPlainText($document);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) <= self::EXCERPT_LENGTH) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::EXCERPT_LENGTH);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim($lastSpace === false ? $cut : mb_substr($cut, 0, $lastSpace)).'…';
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws PageException
     */
    private static function children(mixed $nodes, int $depth, int &$budget): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw PageException::documentIsTooDeep();
        }

        if (! is_array($nodes)) {
            return [];
        }

        $clean = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            foreach (self::node($node, $depth, $budget) as $kept) {
                $clean[] = $kept;
            }
        }

        return $clean;
    }

    /**
     * @param  array<mixed, mixed>  $node
     * @return list<array<string, mixed>>
     *
     * @throws PageException
     */
    private static function node(array $node, int $depth, int &$budget): array
    {
        $type = $node['type'] ?? null;

        if (! is_string($type) || ! array_key_exists($type, self::NODES)) {
            // Unknown nodes are unwrapped so the text inside them is kept.
            return self::children($node['content'] ?? [], $depth, $budget);
        }

        if (--$budget < 0) {
            throw PageException::documentIsTooLarge();
        }

        if ($type === 'text') {
            return self::text($node);
        }

        $clean = ['type' => $type];

        $attributes = self::attributes($type, $node['attrs'] ?? null);

        if ($attributes !== []) {
            $clean['attrs'] = $attributes;
        }

        $content = self::children($node['content'] ?? [], $depth + 1, $budget);

        if ($content !== []) {
            $clean['content'] = $content;
        }

        return [$clean];
    }

    /**
     * @param  array<mixed, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private static function text(array $node): array
    {
        $text = $node['text'] ?? null;

        if (! is_string($text) || $text === '') {
            return [];
        }

        $clean = ['type' => 'text', 'text' => $text];
        $marks = self::marks($node['marks'] ?? null);

        if ($marks !== []) {
            $clean['marks'] = $marks;
        }

        return [$clean];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function marks(mixed $marks): array
    {
        if (! is_array($marks)) {
            return [];
        }

        $clean = [];

        foreach ($marks as $mark) {
            if (! is_array($mark)) {
                continue;
            }

            $type = $mark['type'] ?? null;

            if (! is_string($type) || ! array_key_exists($type, self::MARKS)) {
                continue;
            }

            if ($type === 'link') {
                $href = self::href($mark['attrs']['href'] ?? null);

                if ($href === null) {
                    continue;
                }

                $clean[] = ['type' => 'link', 'attrs' => ['href' => $href]];

                continue;
            }

            $clean[] = ['type' => $type];
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    private static function attributes(string $type, mixed $attributes): array
    {
        if (! is_array($attributes)) {
            return [];
        }

        $clean = [];

        foreach (self::NODES[$type] as $name) {
            if (! array_key_exists($name, $attributes)) {
                continue;
            }

            $value = self::attribute($name, $attributes[$name]);

            if ($value !== null) {
                $clean[$name] = $value;
            }
        }

        return $clean;
    }

    private static function attribute(string $name, mixed $value): mixed
    {
        return match ($name) {
            'level' => is_numeric($value) ? max(1, min(self::MAX_HEADING_LEVEL, (int) $value)) : null,
            'start' => is_numeric($value) ? max(1, (int) $value) : null,
            'checked' => is_bool($value) ? $value : null,
            'colspan', 'rowspan' => is_numeric($value) ? max(1, min(100, (int) $value)) : null,
            'colwidth' => self::columnWidths($value),
            'language' => is_string($value) && preg_match('/^[A-Za-z0-9+#._-]{1,32}$/', $value) === 1 ? $value : null,
            default => null,
        };
    }

    /**
     * @return list<int>|null
     */
    private static function columnWidths(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $widths = [];

        foreach ($value as $width) {
            if (! is_numeric($width)) {
                return null;
            }

            $widths[] = max(1, min(2000, (int) $width));
        }

        return $widths === [] ? null : $widths;
    }

    private static function href(mixed $href): ?string
    {
        if (! is_string($href)) {
            return null;
        }

        $href = trim($href);
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        if ($href === '' || mb_strlen($href) > 2048) {
            return null;
        }

        return $scheme === '' || in_array($scheme, self::ALLOWED_SCHEMES, true) ? $href : null;
    }

    /**
     * @param  array<mixed, mixed>  $node
     */
    private static function textOf(array $node): string
    {
        if (($node['type'] ?? null) === 'text') {
            return is_string($node['text'] ?? null) ? $node['text'] : '';
        }

        $content = $node['content'] ?? null;

        if (! is_array($content)) {
            return '';
        }

        $text = '';

        foreach ($content as $child) {
            if (is_array($child)) {
                $text .= self::textOf($child).' ';
            }
        }

        return $text;
    }
}
