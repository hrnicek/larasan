<?php

declare(strict_types=1);

use App\Domain\Page\Content\PageDocument;
use App\Domain\Page\Exceptions\PageException;

it('refuses anything that is not a document', function (mixed $input): void {
    expect(fn (): array => PageDocument::sanitize($input))->toThrow(PageException::class);
})->with([
    'a string' => ['<p>hello</p>'],
    'a node that is not the root' => [['type' => 'paragraph']],
    'nothing' => [null],
    'a list' => [[['type' => 'doc']]],
]);

it('keeps the blocks the editor is allowed to produce', function (): void {
    $document = doc([
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [textNode('Brief')]],
        ['type' => 'paragraph', 'content' => [textNode('One')]],
        ['type' => 'horizontalRule'],
    ]);

    expect(PageDocument::sanitize($document))->toBe($document);
});

it('unwraps a node it cannot draw and keeps what was written inside it', function (): void {
    $clean = PageDocument::sanitize(doc([
        ['type' => 'iframe', 'content' => [
            ['type' => 'paragraph', 'content' => [textNode('kept')]],
        ]],
    ]));

    expect($clean['content'])->toBe([
        ['type' => 'paragraph', 'content' => [textNode('kept')]],
    ]);
});

it('drops a mark it cannot draw and keeps the words', function (): void {
    $clean = PageDocument::sanitize(doc([
        ['type' => 'paragraph', 'content' => [
            textNode('shouted', [['type' => 'blink'], ['type' => 'bold']]),
        ]],
    ]));

    expect($clean['content'][0]['content'][0])->toBe(textNode('shouted', [['type' => 'bold']]));
});

it('keeps a link that goes somewhere a browser may follow', function (string $href): void {
    $clean = PageDocument::sanitize(doc([
        ['type' => 'paragraph', 'content' => [
            textNode('there', [['type' => 'link', 'attrs' => ['href' => $href]]]),
        ]],
    ]));

    expect($clean['content'][0]['content'][0]['marks'])->toBe([
        ['type' => 'link', 'attrs' => ['href' => $href]],
    ]);
})->with([
    'https' => ['https://example.test/brief'],
    'http' => ['http://example.test'],
    'mailto' => ['mailto:someone@example.test'],
    'a link into this application' => ['/projects/9f1c'],
]);

it('drops a link that is a script with a different spelling', function (mixed $href): void {
    $clean = PageDocument::sanitize(doc([
        ['type' => 'paragraph', 'content' => [
            textNode('there', [['type' => 'link', 'attrs' => ['href' => $href]]]),
        ]],
    ]));

    expect($clean['content'][0]['content'][0])->toBe(textNode('there'));
})->with([
    'javascript' => ['javascript:alert(1)'],
    'data' => ['data:text/html;base64,PHNjcmlwdD4='],
    'empty' => [''],
    'not a string' => [['https://example.test']],
]);

it('drops attributes the node does not carry', function (): void {
    $clean = PageDocument::sanitize(doc([
        ['type' => 'paragraph', 'attrs' => ['onclick' => 'steal()', 'style' => 'position:fixed']],
    ]));

    expect($clean['content'][0])->toBe(['type' => 'paragraph']);
});

it('clamps a heading to the levels the design system draws', function (int $given, int $kept): void {
    $clean = PageDocument::sanitize(doc([
        ['type' => 'heading', 'attrs' => ['level' => $given], 'content' => [textNode('Title')]],
    ]));

    expect($clean['content'][0]['attrs'])->toBe(['level' => $kept]);
})->with([
    'below the range' => [0, 1],
    'inside it' => [2, 2],
    'above it' => [6, 3],
]);

it('keeps a table with its spans', function (): void {
    $clean = PageDocument::sanitize(doc([
        ['type' => 'table', 'content' => [
            ['type' => 'tableRow', 'content' => [
                ['type' => 'tableHeader', 'attrs' => ['colspan' => 2, 'rowspan' => 1, 'colwidth' => [120, 200]], 'content' => [
                    ['type' => 'paragraph', 'content' => [textNode('Owner')]],
                ]],
            ]],
        ]],
    ]));

    expect($clean['content'][0]['content'][0]['content'][0]['attrs'])
        ->toBe(['colspan' => 2, 'rowspan' => 1, 'colwidth' => [120, 200]]);
});

it('keeps a task item and what it says about itself', function (): void {
    $clean = PageDocument::sanitize(doc([
        ['type' => 'taskList', 'content' => [
            ['type' => 'taskItem', 'attrs' => ['checked' => true], 'content' => [
                ['type' => 'paragraph', 'content' => [textNode('Ship it')]],
            ]],
        ]],
    ]));

    expect($clean['content'][0]['content'][0]['attrs'])->toBe(['checked' => true]);
});

it('drops an empty run of text', function (): void {
    $clean = PageDocument::sanitize(doc([
        ['type' => 'paragraph', 'content' => [textNode(''), textNode('kept')]],
    ]));

    expect($clean['content'][0]['content'])->toBe([textNode('kept')]);
});

it('refuses a document nested deeper than a page can hold', function (): void {
    $node = ['type' => 'paragraph', 'content' => [textNode('deep')]];

    for ($level = 0; $level < PageDocument::MAX_DEPTH + 2; $level++) {
        $node = ['type' => 'blockquote', 'content' => [$node]];
    }

    expect(fn (): array => PageDocument::sanitize(doc([$node])))
        ->toThrow(PageException::class, 'nested more deeply');
});

it('refuses a document larger than a page can hold', function (): void {
    $content = array_fill(0, PageDocument::MAX_NODES + 1, ['type' => 'paragraph']);

    expect(fn (): array => PageDocument::sanitize(doc($content)))
        ->toThrow(PageException::class, 'larger than a page');
});

it('reads the words out of a document with one space between blocks', function (): void {
    $text = PageDocument::toPlainText(doc([
        ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [textNode('Brief')]],
        ['type' => 'paragraph', 'content' => [textNode('One'), textNode(' and two')]],
    ]));

    expect($text)->toBe('Brief One and two');
});

it('has no excerpt for a page nobody has written in', function (): void {
    expect(PageDocument::excerpt(PageDocument::empty()))->toBeNull();
});

it('cuts an excerpt on a word rather than mid-word', function (): void {
    $long = str_repeat('word ', 80);
    $excerpt = PageDocument::excerpt(doc([
        ['type' => 'paragraph', 'content' => [textNode($long)]],
    ]));

    expect($excerpt)->toEndWith('…')
        ->and(mb_strlen((string) $excerpt))->toBeLessThanOrEqual(PageDocument::EXCERPT_LENGTH + 1)
        ->and(str_contains((string) $excerpt, 'wor…'))->toBeFalse();
});

it('starts empty as a document rather than as nothing', function (): void {
    expect(PageDocument::empty())->toBe(['type' => 'doc', 'content' => []])
        ->and(PageDocument::sanitize(PageDocument::empty()))->toBe(['type' => 'doc', 'content' => []]);
});
