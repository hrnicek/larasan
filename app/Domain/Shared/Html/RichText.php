<?php

declare(strict_types=1);

namespace App\Domain\Shared\Html;

use DOMDocument;
use DOMElement;
use DOMNode;

final readonly class RichText
{
    /**
     * @var list<string>
     */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'a', 'ol', 'ul', 'li',
        'h1', 'h2', 'h3', 'blockquote', 'pre', 'code',
    ];

    /**
     * Quill 2 stores the list type in `data-list`; without it bulleted lists reload as numbered.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href'],
        'li' => ['data-list'],
    ];

    /**
     * Removed with their content, because parsers do not read what is inside them as ordinary markup.
     *
     * @var list<string>
     */
    private const DROPPED_WITH_CONTENT = [
        'script', 'style', 'template', 'iframe', 'object', 'embed', 'form', 'svg', 'math',
        'xmp', 'noembed', 'noframes', 'noscript', 'plaintext', 'textarea', 'title',
    ];

    public static function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        // The XML encoding declaration stops DOMDocument from reading UTF-8 as Latin-1.
        $document->loadHTML(
            '<?xml encoding="UTF-8"?><div id="rich-text-root">'.$html.'</div>',
            LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('rich-text-root');

        if (! $root instanceof DOMElement) {
            return null;
        }

        self::clean($root);

        $clean = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $clean .= $document->saveHTML($child);
        }

        return self::readable($root) ? trim($clean) : null;
    }

    public static function toPlainText(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $text = html_entity_decode(
            (string) preg_replace('/<[^>]*>/', ' ', $html),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Iterates a copy because unwrapping a child mutates the live node list. */
    private static function clean(DOMElement $element): void
    {
        foreach (iterator_to_array($element->childNodes) as $child) {
            // Matched by type because DOMCdataSection extends DOMText and is saved back unescaped.
            if ($child->nodeType === XML_TEXT_NODE) {
                continue;
            }

            if (! $child instanceof DOMElement || in_array($child->nodeName, self::DROPPED_WITH_CONTENT, true)) {
                $element->removeChild($child);

                continue;
            }

            self::clean($child);

            if (! in_array($child->nodeName, self::ALLOWED_TAGS, true)) {
                self::unwrap($child);

                continue;
            }

            self::stripAttributes($child);
        }
    }

    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            $parent->insertBefore($child, $element);
        }

        $parent->removeChild($element);
    }

    private static function stripAttributes(DOMElement $element): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$element->nodeName] ?? [];

        // Iterate a copy because removing an attribute mutates the live map.
        foreach (iterator_to_array($element->attributes) as $attribute) {
            if (! in_array($attribute->name, $allowed, true)) {
                $element->removeAttribute($attribute->name);
            }
        }

        if ($element->nodeName === 'a') {
            self::cleanLink($element);
        }
    }

    private static function cleanLink(DOMElement $element): void
    {
        $href = LinkHref::sanitize($element->getAttribute('href'));

        if ($href === null) {
            $element->removeAttribute('href');

            return;
        }

        $element->setAttribute('href', $href);
        $element->setAttribute('target', '_blank');
        $element->setAttribute('rel', 'noopener noreferrer nofollow');
    }

    private static function readable(DOMNode $node): bool
    {
        return trim((string) $node->textContent) !== '';
    }
}
