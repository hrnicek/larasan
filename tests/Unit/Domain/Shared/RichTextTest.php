<?php

declare(strict_types=1);

use App\Domain\Shared\Html\RichText;
use Random\Engine\Mt19937;
use Random\Randomizer;

// The allowlist is the only barrier between a task description and stored script.
it('keeps the formatting the editor produces', function (string $html): void {
    expect(RichText::sanitize($html))->toBe($html);
})->with([
    'a paragraph' => ['<p>Hello</p>'],
    'emphasis' => ['<p><strong>bold</strong> and <em>italic</em></p>'],
    'a heading' => ['<h2>Section</h2>'],
    'a list, with the kind Quill records on the line' => ['<ol><li data-list="bullet">one</li></ol>'],
    'a quote' => ['<blockquote>said</blockquote>'],
    'code' => ['<pre>a &amp;&amp; b</pre>'],
]);

it('drops a script rather than unwrapping it', function (): void {
    expect(RichText::sanitize('<p>before</p><script>alert(1)</script><p>after</p>'))
        ->toBe('<p>before</p><p>after</p>');
});

it('drops every attribute the allowlist does not name', function (): void {
    expect(RichText::sanitize('<p onclick="steal()" class="x" style="color:red">words</p>'))
        ->toBe('<p>words</p>');
});

it('keeps the words of an unknown tag and loses the tag', function (): void {
    expect(RichText::sanitize('<div><span>plain</span></div>'))->toBe('plain');
});

it('refuses a link that is a script in a different spelling', function (string $href): void {
    expect(RichText::sanitize('<a href="'.$href.'">go</a>'))->toBe('<a>go</a>');
})->with([
    'javascript' => ['javascript:alert(1)'],
    'data' => ['data:text/html;base64,PHNjcmlwdD4='],
    'vbscript' => ['vbscript:msgbox(1)'],
    'an upper-case scheme' => ['JaVaScRiPt:alert(1)'],
    'a tab inside the scheme' => ["java\tscript:alert(1)"],
    'a newline inside the scheme' => ["java\nscript:alert(1)"],
    'a tab written as an entity' => ['java&#9;script:alert(1)'],
    'a newline written as an entity' => ['java&#x0A;script:alert(1)'],
    'a leading control character' => ["\x01javascript:alert(1)"],
    'leading spaces and a null byte' => [" \x00 javascript:alert(1)"],
    'a space before the colon' => ['javascript :alert(1)'],
    'a leading non-breaking space' => ["\u{00A0}javascript:alert(1)"],
    'a colon written as an entity' => ['javascript&colon;alert(1)'],
    'protocol-relative' => ['//evil.example'],
    'protocol-relative with a backslash' => ['/\\evil.example'],
    'protocol-relative with two backslashes' => ['\\\\evil.example'],
    'protocol-relative behind a tab' => ["\t/\t/evil.example"],
]);

it('hardens the links it keeps', function (): void {
    expect(RichText::sanitize('<a href="https://example.com">go</a>'))
        ->toBe('<a href="https://example.com" target="_blank" rel="noopener noreferrer nofollow">go</a>');
});

it('keeps a link a browser may follow', function (string $href, string $kept): void {
    expect(RichText::sanitize('<a href="'.$href.'">go</a>'))
        ->toBe('<a href="'.$kept.'" target="_blank" rel="noopener noreferrer nofollow">go</a>');
})->with([
    'an upper-case https' => ['HTTPS://example.com', 'HTTPS://example.com'],
    'mailto' => ['mailto:someone@example.test', 'mailto:someone@example.test'],
    'a path into this application' => ['/projects/9f1c', '/projects/9f1c'],
    'a fragment' => ['#details', '#details'],
    'a colon after the path starts' => ['/search?at=10:30', '/search?at=10:30'],
    'surrounding whitespace' => [" https://example.com\n", 'https://example.com'],
]);

it('treats an empty editor as no description at all', function (?string $html): void {
    expect(RichText::sanitize($html))->toBeNull();
})->with([
    'nothing' => [null],
    'whitespace' => ['   '],
    'the empty document Quill sends' => ['<p><br></p>'],
    'an image with a payload and no words' => ['<img src=x onerror=alert(1)>'],
]);

it('does not mangle non-ASCII text', function (): void {
    expect(RichText::sanitize('<p>Příliš žluťoučký kůň</p>'))->toBe('<p>Příliš žluťoučký kůň</p>');
});

/**
 * @return list<string>
 */
function markupOutsideTheRichTextAllowlist(string $html): array
{
    $allowed = [
        'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'a' => ['href', 'target', 'rel'], 'ol' => [], 'ul' => [], 'li' => ['data-list'],
        'h1' => [], 'h2' => [], 'h3' => [], 'blockquote' => [], 'pre' => [], 'code' => [],
    ];

    $found = [];

    $tag = '/<\/?([a-zA-Z][a-zA-Z0-9]*)(?:\s+[a-zA-Z-]+(?:="[^"]*")?)*\s*>/';

    preg_match_all($tag, $html, $tags);

    foreach ($tags[1] as $name) {
        if (! array_key_exists(strtolower($name), $allowed)) {
            $found[] = "the tag <{$name}> in the markup";
        }
    }

    if (str_contains((string) preg_replace($tag, '', $html), '<')) {
        $found[] = 'a < that does not open an allowlisted tag';
    }

    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"?><div id="reparsed">'.$html.'</div>', LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = $document->getElementById('reparsed');
    $pending = $root instanceof DOMElement ? iterator_to_array($root->childNodes) : [];

    while ($pending !== []) {
        $node = array_shift($pending);

        if ($node instanceof DOMElement) {
            if (! array_key_exists($node->nodeName, $allowed)) {
                $found[] = "the element <{$node->nodeName}> after parsing";
            }

            foreach (iterator_to_array($node->attributes) as $attribute) {
                if (str_starts_with($attribute->name, 'on') || ! in_array($attribute->name, $allowed[$node->nodeName] ?? [], true)) {
                    $found[] = "the attribute {$attribute->name} on <{$node->nodeName}>";
                }
            }

            array_push($pending, ...iterator_to_array($node->childNodes));

            continue;
        }

        if ($node->nodeType !== XML_TEXT_NODE) {
            $found[] = "a {$node->nodeName} node after parsing";
        }
    }

    return $found;
}

it('drops an element whose content a parser reads as raw text, content and all', function (string $payload): void {
    expect(RichText::sanitize('<p>hi</p>'.$payload))->toBe('<p>hi</p>');
})->with([
    'xmp' => ['<xmp><img src=x onerror=alert(document.domain)></xmp>'],
    'noembed' => ['<noembed><img src=x onerror=alert(1)></noembed>'],
    'noframes' => ['<noframes><img src=x onerror=alert(1)></noframes>'],
    'plaintext' => ['<plaintext><img src=x onerror=alert(1)></plaintext>'],
    'noscript' => ['<noscript><img src=x onerror=alert(1)></noscript>'],
    'iframe' => ['<iframe><img src=x onerror=alert(1)></iframe>'],
    'textarea' => ['<textarea><img src=x onerror=alert(1)></textarea>'],
    'title' => ['<title><img src=x onerror=alert(1)></title>'],
    'style' => ['<style><img src=x onerror=alert(1)></style>'],
    'script' => ['<script><img src=x onerror=alert(1)></script>'],
    'an upper-case script' => ['<SCRIPT><img src=x onerror=alert(1)></SCRIPT>'],
    'template' => ['<template><img src=x onerror=alert(1)></template>'],
    'a style inside svg' => ['<svg><style><img src=x onerror=alert(1)></style></svg>'],
    'a style inside math' => ['<math><style><img src=x onerror=alert(1)></style></math>'],
]);

it('drops a raw text element nested inside formatting it keeps', function (): void {
    expect(RichText::sanitize('<p>hi <code><xmp><img src=x onerror=alert(1)></xmp></code></p>'))
        ->toBe('<p>hi <code></code></p>');
});

it('keeps no comment, processing instruction or CDATA section', function (string $payload): void {
    $clean = (string) RichText::sanitize('<p>hi</p>'.$payload);

    expect($clean)->toStartWith('<p>hi</p>')
        ->not->toContain('<img')
        ->and(markupOutsideTheRichTextAllowlist($clean))->toBe([]);
})->with([
    'a comment' => ['<!-- <img src=x onerror=alert(1)> -->'],
    'a comment that closes early' => ['<!--><img src=x onerror=alert(1)>-->'],
    'a comment inside kept formatting' => ['<p><!-- --><strong>x</strong></p>'],
    'a php processing instruction' => ['<?php <img src=x onerror=alert(1)> ?>'],
    'a short processing instruction' => ['<? <img src=x onerror=alert(1)> ?>'],
    'an xml declaration' => ['<?xml version="1.0"?>'],
    'a CDATA section' => ['<![CDATA[<img src=x onerror=alert(1)>]]>'],
    'a doctype' => ['<!DOCTYPE html>'],
]);

it('emits only allowlisted markup whatever it is given', function (): void {
    $random = new Randomizer(new Mt19937(20260912));

    $tags = [
        'p', 'strong', 'a', 'li', 'ol', 'pre', 'code', 'blockquote', 'h2', 'div', 'span', 'img', 'svg', 'math',
        'table', 'select', 'option', 'object', 'embed', 'form', 'xmp', 'noembed', 'noframes', 'plaintext',
        'noscript', 'iframe', 'textarea', 'title', 'style', 'script', 'template', 'SCRIPT', 'Xmp',
    ];
    $attributes = [
        '', ' onerror=alert(1)', ' onclick="alert(1)"', ' OnMouseOver=alert(1)', ' href="javascript:alert(1)"',
        ' href="java&#9;script:alert(1)"', ' href="//evil.example"', ' href="https://example.test"',
        ' href="https://example.test/&quot;&gt;&lt;img src=x onerror=alert(1)&gt;"', ' href=" /&#9;/evil.example"',
        ' data-list="bullet"', ' style="x:expression(alert(1))"', ' src=x', ' srcdoc="<script>alert(1)</script>"',
    ];
    $texts = [
        'words', '<img src=x onerror=alert(1)>', '&lt;img src=x onerror=alert(1)&gt;', '"', "'", ']]>', '<!--',
        '-->', '<?', '<![CDATA[', '</p>', '</xmp>', '</title>', '<br>', '&amp;', 'Příliš',
    ];

    $fragment = function (int $depth) use (&$fragment, $random, $tags, $attributes, $texts): string {
        $html = '';

        for ($count = $random->getInt(1, 3); $count > 0; $count--) {
            if ($depth === 0 || $random->getInt(0, 2) === 0) {
                $html .= $texts[$random->getInt(0, count($texts) - 1)];

                continue;
            }

            $tag = $tags[$random->getInt(0, count($tags) - 1)];
            $attribute = $attributes[$random->getInt(0, count($attributes) - 1)];

            $html .= "<{$tag}{$attribute}>".$fragment($depth - 1)."</{$tag}>";
        }

        return $html;
    };

    $checked = 0;

    for ($run = 0; $run < 400; $run++) {
        $input = $fragment(4);
        $clean = RichText::sanitize($input);

        if ($clean === null) {
            continue;
        }

        $checked++;

        expect(markupOutsideTheRichTextAllowlist($clean))->toBe([], "Input: {$input}\nOutput: {$clean}");
    }

    expect($checked)->toBeGreaterThan(200);
});
