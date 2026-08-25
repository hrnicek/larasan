<?php

declare(strict_types=1);

namespace App\Domain\Page\Content;

use App\Domain\Page\Exceptions\PageException;

/**
 * What a page is allowed to be.
 *
 * A page is stored as ProseMirror's own document JSON rather than as markup (ADR-0017), and
 * this is the reason that choice is worth anything: a document made of named nodes can be
 * reduced to the vocabulary this application draws *before* it is written, and drawn back by
 * the editor that produced it — so a page never reaches a browser as markup the server did
 * not build, and `v-html` is never involved.
 *
 * An allowlist, never a denylist. A node this application cannot draw loses its own type and
 * keeps what was written inside it, the way `RichText` unwraps an unknown tag; a mark it
 * cannot draw loses the mark and keeps the words.
 *
 * Done here rather than in a FormRequest because it has to hold for every caller — console,
 * queue and any future API (`.ai/security`).
 */
final readonly class PageDocument
{
    /**
     * Deep enough for a list inside a list inside a table cell, shallow enough that walking
     * one is bounded work. A document past this is refused rather than truncated: silently
     * dropping the end of somebody's page is worse than saying no.
     */
    public const MAX_DEPTH = 12;

    /** The same argument, applied to breadth. A page is a document, not a database. */
    public const MAX_NODES = 5000;

    public const EXCERPT_LENGTH = 200;

    /**
     * Every node the editor may produce, with the attributes each one keeps. Anything else
     * is unwrapped; anything else in `attrs` is dropped.
     *
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
     * The marks a run of text may carry. `link` keeps its destination and nothing else —
     * `target` and `rel` are decided where the link is rendered, not by whoever wrote it.
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

    /** Anything else is a way to make a link do something other than go somewhere. */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    /** Headings go as far as the design system draws them, and no further (`DESIGN.md`). */
    private const MAX_HEADING_LEVEL = 3;

    /**
     * What a page holds before anybody has written in it. A column that cannot be null needs
     * an empty value that is still a document.
     *
     * @return array<string, mixed>
     */
    public static function empty(): array
    {
        return ['type' => 'doc', 'content' => []];
    }

    /**
     * The document, reduced to what this application can draw.
     *
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
     * The words, without the document around them: what a search engine and a one-line
     * preview both want.
     *
     * @param  array<string, mixed>  $document
     */
    public static function toPlainText(array $document): string
    {
        $text = self::textOf($document);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The first words of a page, kept beside it so a list of pages does not have to read
     * every document it names.
     *
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

        // Cutting mid-word reads as a typo rather than as an excerpt.
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
     * One node, as the list of nodes that survive it: itself when this application draws it,
     * its children when it does not, and nothing when neither is worth keeping.
     *
     * @param  array<mixed, mixed>  $node
     * @return list<array<string, mixed>>
     *
     * @throws PageException
     */
    private static function node(array $node, int $depth, int &$budget): array
    {
        $type = $node['type'] ?? null;

        if (! is_string($type) || ! array_key_exists($type, self::NODES)) {
            // Unwrapped, not deleted: the words inside an unknown node were still written by
            // somebody, and they belong where the node was.
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

                // A link with nowhere to go is not a link. The words stay; the mark does not.
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

        // A relative link stays: it addresses this application, which is where a page links
        // to a task. Anything with a scheme has to be one a browser may safely follow.
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
                // A space between blocks, so `one</p><p>two` does not become one word — the
                // problem `RichText::toPlainText()` solves the same way.
                $text .= self::textOf($child).' ';
            }
        }

        return $text;
    }
}
