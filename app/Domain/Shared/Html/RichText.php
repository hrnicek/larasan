<?php

declare(strict_types=1);

namespace App\Domain\Shared\Html;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * What a person is allowed to have written.
 *
 * A description is rich text now, which means the browser sends markup and the browser will one
 * day render it back. Markup that arrives from a client is not markup that may be rendered: the
 * only version of it worth storing is one that has already been reduced to the tags this
 * application draws.
 *
 * Done here rather than in a FormRequest because it has to hold for every caller — console,
 * queue and any future API — and a rule enforced at the edge is a rule the next entry point does
 * not have (`docs/conventions/security.md`).
 *
 * An allowlist, never a denylist: the tags below are the whole vocabulary, and everything else
 * loses its tag and keeps its words. `<script>` is the exception that loses both, because its
 * words are the payload.
 */
final readonly class RichText
{
    /**
     * The tags the editor can produce and the screen can draw.
     *
     * @var list<string>
     */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'a', 'ol', 'ul', 'li',
        'h1', 'h2', 'h3', 'blockquote', 'pre', 'code',
    ];

    /**
     * Attributes, per tag. `data-list` is how Quill 2 says which kind of list a line is, so
     * dropping it turns every bulleted list into a numbered one on the way back in.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href'],
        'li' => ['data-list'],
    ];

    /** Anything else is a way to make a link do something other than go somewhere. */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    /**
     * Whatever survives the allowlist, or null when nothing readable is left.
     *
     * An empty editor sends `<p><br></p>`, and storing that would make "no description" a thing
     * the screen has to recognise rather than an absence.
     */
    public static function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        // A fragment rather than a document: the wrapper is stripped below, and the encoding
        // declaration is what stops DOMDocument reading UTF-8 as Latin-1.
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

        self::dropDangerousElements($document);
        self::clean($root);

        $clean = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $clean .= $document->saveHTML($child);
        }

        return self::readable($root) ? trim($clean) : null;
    }

    /**
     * The words, without the markup around them.
     *
     * What a search index wants: `p`, `li`, `strong` and every attribute value are terms to a
     * search engine, so an unstripped description makes *strong* return every task somebody had
     * emboldened a word in. The generated column that feeds the PostgreSQL path solves the same
     * problem with `regexp_replace` (ADR-0012); this is that rule for the engine (ADR-0016).
     *
     * A space in place of each tag, so `one</p><p>two` does not become one word.
     */
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

    /**
     * Elements whose *content* is the danger rather than their tag. Unwrapping a `<script>` the
     * way an unknown tag is unwrapped would leave its source in the text.
     */
    private static function dropDangerousElements(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//script | //style | //iframe | //object | //embed | //form');

        if ($nodes === false) {
            return;
        }

        foreach (iterator_to_array($nodes) as $node) {
            // A namespace node is the one thing an XPath result can be that no parent holds.
            if ($node instanceof DOMNode) {
                $node->parentNode?->removeChild($node);
            }
        }
    }

    /** Depth-first, over a copy of the child list: unwrapping a node edits the list being read. */
    private static function clean(DOMElement $element): void
    {
        foreach (iterator_to_array($element->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
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

    /** The tag goes; what was written inside it stays where it was. */
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

        // Over a copy: removing an attribute edits the map being read.
        foreach (iterator_to_array($element->attributes) as $attribute) {
            if (! in_array($attribute->name, $allowed, true)) {
                $element->removeAttribute($attribute->name);
            }
        }

        if ($element->nodeName === 'a') {
            self::cleanLink($element);
        }
    }

    /**
     * A link goes somewhere on the web or to an inbox. `javascript:` is a script with a different
     * spelling, and `data:` is a page of somebody else's making served from this origin.
     */
    private static function cleanLink(DOMElement $element): void
    {
        $href = trim($element->getAttribute('href'));
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        if ($href === '' || ($scheme !== '' && ! in_array($scheme, self::ALLOWED_SCHEMES, true))) {
            $element->removeAttribute('href');

            return;
        }

        // Opening in a new tab hands the opener to whatever is at the other end without these.
        $element->setAttribute('target', '_blank');
        $element->setAttribute('rel', 'noopener noreferrer nofollow');
    }

    /** Whether anything was actually written, as opposed to an empty paragraph. */
    private static function readable(DOMNode $node): bool
    {
        return trim((string) $node->textContent) !== '';
    }
}
