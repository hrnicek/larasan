<?php

declare(strict_types=1);

use App\Domain\Page\Content\PageDocument;
use App\Domain\Page\Models\Page;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;

it('starts a page in a project', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);

    $this->actingAs($actor)
        ->post(route('projects.pages.store', $project), ['title' => 'Product brief'])
        ->assertRedirect();

    expect(Page::query()->where('project_id', $project->id)->value('title'))->toBe('Product brief');
});

it('starts a page underneath another one', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $parent = Page::factory()->in($project)->create();

    $this->actingAs($actor)
        ->post(route('projects.pages.store', $project), ['title' => 'Notes', 'parent' => $parent->id])
        ->assertRedirect();

    expect(Page::query()->where('parent_id', $parent->id)->value('title'))->toBe('Notes');
});

it('refuses a parent from another project before the Action is reached', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $foreign = Page::factory()->create();

    $this->actingAs($actor)
        ->post(route('projects.pages.store', $project), ['title' => 'Notes', 'parent' => $foreign->id])
        ->assertSessionHasErrors('parent');

    expect(Page::query()->count())->toBe(1);
});

it('refuses a viewer who posts anyway', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Viewer);

    $this->actingAs($actor)
        ->post(route('projects.pages.store', $project), ['title' => 'Mine'])
        ->assertForbidden();

    expect(Page::query()->count())->toBe(0);
});

it('hides a project in another workspace behind a 404 rather than a permission error', function (): void {
    [$project] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    [, $stranger] = workspaceWith(WorkspaceRole::Owner);

    $this->actingAs($stranger)
        ->post(route('projects.pages.store', $project), ['title' => 'Mine'])
        ->assertNotFound();

    expect(Page::query()->count())->toBe(0);
});

it('renames a page', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create();

    $this->actingAs($actor)
        ->put(route('pages.title.update', $page), ['title' => 'Renamed'])
        ->assertRedirect();

    expect($page->fresh()?->title)->toBe('Renamed');
});

it('refuses a rename from a viewer', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Viewer);
    $page = Page::factory()->in($project)->titled('Brief')->create();

    $this->actingAs($actor)
        ->put(route('pages.title.update', $page), ['title' => 'Theirs'])
        ->assertForbidden();

    expect($page->fresh()?->title)->toBe('Brief');
});

it('saves the document and answers with the new version', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create(['version' => 1]);

    $response = $this->actingAs($actor)->putJson(route('pages.content.update', $page), [
        'content' => doc([['type' => 'paragraph', 'content' => [textNode('Written')]]]),
        'version' => 1,
    ]);

    $response->assertOk()->assertJsonPath('version', 2);

    expect($page->fresh()?->excerpt)->toBe('Written');
});

it('answers a save written against an older version with a conflict', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create(['version' => 5]);

    $this->actingAs($actor)
        ->putJson(route('pages.content.update', $page), [
            'content' => doc([['type' => 'paragraph', 'content' => [textNode('Mine')]]]),
            'version' => 4,
        ])
        ->assertStatus(409)
        ->assertJsonPath('version', 5);

    expect($page->fresh()?->version)->toBe(5);
});

it('refuses a document that is not one', function (mixed $content): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create();

    $this->actingAs($actor)
        ->putJson(route('pages.content.update', $page), ['content' => $content, 'version' => 1])
        ->assertStatus(422);
})->with([
    'a string' => ['<p>hello</p>'],
    'the wrong root' => [['type' => 'paragraph']],
    'nothing' => [null],
]);

it('refuses a save from a commenter', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Commenter);
    $page = Page::factory()->in($project)->create();

    $this->actingAs($actor)
        ->putJson(route('pages.content.update', $page), [
            'content' => PageDocument::empty(),
            'version' => $page->version,
        ])
        ->assertForbidden();
});

it('moves a page in the tree', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $parent = Page::factory()->in($project)->create();
    $page = Page::factory()->in($project)->create();

    $this->actingAs($actor)
        ->put(route('pages.placement.update', $page), ['parent' => $parent->id])
        ->assertRedirect();

    expect($page->fresh()?->parent_id)->toBe($parent->id);
});

it('places a page behind a sibling under a new parent in one request', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $parent = Page::factory()->in($project)->create();
    $first = Page::factory()->under($parent)->create();
    $page = Page::factory()->in($project)->create();

    $this->actingAs($actor)
        ->put(route('pages.placement.update', $page), ['parent' => $parent->id, 'after' => $first->id])
        ->assertRedirect();

    expect($page->fresh()?->parent_id)->toBe($parent->id)
        ->and($page->fresh()?->position)->toBeGreaterThan($first->fresh()?->position ?? 0);
});

it('moves a page out beside its own parent', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $parent = Page::factory()->in($project)->create();
    $child = Page::factory()->under($parent)->create();

    $this->actingAs($actor)
        ->put(route('pages.placement.update', $child), ['parent' => null, 'after' => $parent->id])
        ->assertRedirect();

    expect($child->fresh()?->parent_id)->toBeNull()
        ->and($child->fresh()?->position)->toBeGreaterThan($parent->fresh()?->position ?? 0);
});

it('refuses a parent from another project on a move', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create();
    $foreign = Page::factory()->create();

    $this->actingAs($actor)
        ->put(route('pages.placement.update', $page), ['parent' => $foreign->id])
        ->assertSessionHasErrors('parent');

    expect($page->fresh()?->parent_id)->toBeNull();
});

it('refuses a page as its own parent before the Action is reached', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create();

    $this->actingAs($actor)
        ->put(route('pages.placement.update', $page), ['parent' => $page->id])
        ->assertSessionHasErrors('parent');
});

it('removes a page and what was written underneath it', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create();
    Page::factory()->under($page)->create();

    $this->actingAs($actor)
        ->delete(route('pages.destroy', $page))
        ->assertRedirect();

    expect(Page::query()->count())->toBe(0);
});

it('refuses a delete from a viewer', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Viewer);
    $page = Page::factory()->in($project)->create();

    $this->actingAs($actor)->delete(route('pages.destroy', $page))->assertForbidden();

    expect(Page::query()->count())->toBe(1);
});

it('hides a page in another workspace behind a 404, whatever the method', function (string $method): void {
    [$project] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create();
    [, $stranger] = workspaceWith(WorkspaceRole::Owner);

    $payload = match ($method) {
        'title' => [route('pages.title.update', $page), 'put', ['title' => 'Theirs']],
        'content' => [route('pages.content.update', $page), 'put', ['content' => PageDocument::empty(), 'version' => 1]],
        'placement' => [route('pages.placement.update', $page), 'put', []],
        default => [route('pages.destroy', $page), 'delete', []],
    };

    $this->actingAs($stranger)->{$payload[1]}($payload[0], $payload[2])->assertNotFound();

    expect($page->fresh()?->version)->toBe(1)
        ->and($page->fresh()?->deleted_at)->toBeNull();
})->with(['title', 'content', 'placement', 'delete']);

it('hides a page of a private project the actor was never given', function (): void {
    [$project] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor, ProjectVisibility::Private);
    $page = Page::factory()->in($project)->create();
    $outsider = memberOf($project->workspace, WorkspaceRole::Member);

    $this->actingAs($outsider)
        ->put(route('pages.title.update', $page), ['title' => 'Theirs'])
        ->assertNotFound();
});

it('turns an anonymous request away from every page endpoint', function (): void {
    [$project] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create();

    $this->post(route('projects.pages.store', $project))->assertRedirect(route('login'));
    $this->put(route('pages.title.update', $page))->assertRedirect(route('login'));
    $this->delete(route('pages.destroy', $page))->assertRedirect(route('login'));
});
