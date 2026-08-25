<?php

declare(strict_types=1);

use App\Domain\Page\Actions\RenamePage;
use App\Domain\Page\Exceptions\PageException;
use App\Domain\Page\Models\Page;
use App\Domain\Shared\Enums\ProjectAccessLevel;

it('renames a page', function (): void {
    [$project, $actor] = projectWithWriter();
    $page = Page::factory()->in($project)->titled('Brief')->create();

    expect(app(RenamePage::class)->handle($page, $actor, '  Product brief ')->title)
        ->toBe('Product brief');
});

it('leaves the version alone, so an open editor keeps saving', function (): void {
    [$project, $actor] = projectWithWriter();
    $page = Page::factory()->in($project)->create(['version' => 3]);

    app(RenamePage::class)->handle($page, $actor, 'Renamed');

    expect($page->fresh()?->version)->toBe(3);
});

it('calls a nameless page untitled', function (): void {
    [$project, $actor] = projectWithWriter();
    $page = Page::factory()->in($project)->create();

    expect(app(RenamePage::class)->handle($page, $actor, '   ')->title)->toBe(Page::UNTITLED);
});

it('refuses a viewer', function (): void {
    [$project, $actor] = projectWithWriter(ProjectAccessLevel::Viewer);
    $page = Page::factory()->in($project)->titled('Brief')->create();

    expect(fn (): Page => app(RenamePage::class)->handle($page, $actor, 'Theirs'))
        ->toThrow(PageException::class);

    expect($page->fresh()?->title)->toBe('Brief');
});
