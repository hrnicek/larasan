<?php

declare(strict_types=1);

use App\Domain\Page\Actions\CreatePage;
use App\Domain\Page\Actions\DeletePage;
use App\Domain\Page\Actions\MovePage;
use App\Domain\Page\Data\CreatePageData;
use App\Domain\Page\Models\Page;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Ordering\SparsePosition;

it('creates a page in the slot a deleted sibling left behind', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $create = app(CreatePage::class);

    $create->handle($project, $actor, CreatePageData::titled('A'));
    $deleted = $create->handle($project, $actor, CreatePageData::titled('B'));

    app(DeletePage::class)->handle($deleted, $actor);

    $page = $create->handle($project, $actor, CreatePageData::titled('C'));

    expect($page->position)->toBe($deleted->position)
        ->and(Page::query()->where('project_id', $project->id)->orderBy('position')->pluck('title')->all())
        ->toBe(['A', 'C']);
});

it('creates a child in the slot a deleted child left behind', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $create = app(CreatePage::class);
    $parent = $create->handle($project, $actor, CreatePageData::titled('Parent'));

    $deleted = $create->handle($project, $actor, CreatePageData::titled('Child'), $parent);
    app(DeletePage::class)->handle($deleted, $actor);

    $page = $create->handle($project, $actor, CreatePageData::titled('Replacement'), $parent);

    expect($page->parent_id)->toBe($parent->id)
        ->and($page->position)->toBe(SparsePosition::GAP);
});

it('moves a page behind the last sibling when a deleted page held the next slot', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $create = app(CreatePage::class);

    $first = $create->handle($project, $actor, CreatePageData::titled('A'));
    $second = $create->handle($project, $actor, CreatePageData::titled('B'));
    $deleted = $create->handle($project, $actor, CreatePageData::titled('C'));

    app(DeletePage::class)->handle($deleted, $actor);

    $moved = app(MovePage::class)->handle($first, $actor, null, $second);

    expect($moved->position)->toBe($deleted->position)
        ->and(Page::query()->where('project_id', $project->id)->orderBy('position')->pluck('title')->all())
        ->toBe(['B', 'A']);
});

it('moves a page under a parent whose deleted child held the first slot', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $create = app(CreatePage::class);
    $parent = $create->handle($project, $actor, CreatePageData::titled('Parent'));
    $page = $create->handle($project, $actor, CreatePageData::titled('Loose'));

    app(DeletePage::class)->handle($create->handle($project, $actor, CreatePageData::titled('Gone'), $parent), $actor);

    $moved = app(MovePage::class)->handle($page, $actor, $parent, null);

    expect($moved->parent_id)->toBe($parent->id)
        ->and($moved->position)->toBe(SparsePosition::GAP);
});
