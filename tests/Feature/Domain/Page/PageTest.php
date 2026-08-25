<?php

declare(strict_types=1);

use App\Domain\Page\Models\Page;
use App\Domain\Project\Models\Project;

it('reads its document back as an array', function (): void {
    $page = Page::factory()->create([
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
    ]);

    expect($page->fresh()->content)->toBe(['type' => 'doc', 'content' => [['type' => 'paragraph']]]);
});

it('reaches its workspace through its project', function (): void {
    $project = Project::factory()->create();
    $page = Page::factory()->in($project)->create();

    expect($page->project->workspace_id)->toBe($project->workspace_id);
});

it('orders its children by position', function (): void {
    $parent = Page::factory()->create();
    $second = Page::factory()->under($parent)->at(Page::POSITION_GAP * 2)->create();
    $first = Page::factory()->under($parent)->at(Page::POSITION_GAP)->create();

    expect($parent->children->pluck('id')->all())->toBe([$first->id, $second->id]);
});

it('knows whether it sits at the root', function (): void {
    $parent = Page::factory()->create();

    expect($parent->isRoot())->toBeTrue()
        ->and(Page::factory()->under($parent)->create()->isRoot())->toBeFalse();
});
