<?php

declare(strict_types=1);

use App\Domain\Shared\Html\RichText;

/**
 * The allowlist is the only thing standing between a task description and stored script, so
 * every case that must not survive it is pinned here rather than left to a reading of the code.
 */
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
]);

it('hardens the links it keeps', function (): void {
    expect(RichText::sanitize('<a href="https://example.com">go</a>'))
        ->toBe('<a href="https://example.com" target="_blank" rel="noopener noreferrer nofollow">go</a>');
});

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
