<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;

it('creates a tag', function (): void {
    [$workspace, , $actor] = placeableProject();

    $this->actingAs($actor)
        ->post(route('tags.store'), ['name' => '  Bug  ', 'color' => ProjectColor::Rose->value])
        ->assertRedirect();

    $tag = Tag::query()->sole();

    // Trimmed by the Action: the endpoint is a transport and nothing more.
    expect($tag->name)->toBe('Bug')
        ->and($tag->color)->toBe(ProjectColor::Rose)
        ->and($tag->workspace_id)->toBe($workspace->id);
});

it('refuses a second tag with the same name in any case', function (): void {
    [$workspace, , $actor] = placeableProject();
    Tag::factory()->in($workspace)->named('Bug')->create();

    // The race is real — two people can create "Bug" in the same second — so the unique index is
    // what answers, and the Action turns it into a refusal.
    $this->actingAs($actor)
        ->from(route('dashboard'))
        ->post(route('tags.store'), ['name' => 'bug'])
        ->assertSessionHas('errors');

    expect(Tag::query()->count())->toBe(1);
});

it('refuses somebody without tag.manage', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);

    // Applying a tag is editing a task; inventing one changes what everybody's filters mean.
    $this->actingAs($guest)
        ->post(route('tags.store'), ['name' => 'Bug'])
        ->assertForbidden();

    expect(Tag::query()->count())->toBe(0);
});

it('renames and recolours a tag', function (): void {
    [$workspace, , $actor] = placeableProject();
    $tag = Tag::factory()->in($workspace)->named('Buug')->create(['color' => ProjectColor::Amber]);

    $this->actingAs($actor)->put(route('tags.update', $tag), ['name' => 'Bug'])->assertRedirect();
    expect($tag->fresh()?->name)->toBe('Bug')
        ->and($tag->fresh()?->color)->toBe(ProjectColor::Amber);

    $this->actingAs($actor)->put(route('tags.update', $tag), ['color' => ProjectColor::Teal->value])->assertRedirect();
    expect($tag->fresh()?->color)->toBe(ProjectColor::Teal)
        ->and($tag->fresh()?->name)->toBe('Bug');

    // An explicit null clears it; an absent key leaves it alone.
    $this->actingAs($actor)->put(route('tags.update', $tag), ['color' => null])->assertRedirect();
    expect($tag->fresh()?->color)->toBeNull();
});

it('deletes a tag without deleting the work it was on', function (): void {
    [$workspace, , $actor] = placeableProject();
    $tag = Tag::factory()->in($workspace)->create();
    $task = Task::factory()->in($workspace)->create();
    tagTask($task, $tag, $actor);

    $this->actingAs($actor)->delete(route('tags.destroy', $tag))->assertRedirect();

    expect(Tag::query()->count())->toBe(0)
        ->and(Task::query()->whereKey($task->id)->exists())->toBeTrue()
        ->and($task->tags()->count())->toBe(0);
});

it('hides a tag from another workspace behind a 404', function (): void {
    [, , $actor] = placeableProject();
    $elsewhere = Tag::factory()->create();

    $this->actingAs($actor)->put(route('tags.update', $elsewhere), ['name' => 'Mine'])->assertNotFound();
    $this->actingAs($actor)->delete(route('tags.destroy', $elsewhere))->assertNotFound();
});

it('puts a tag on a task and takes it off', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $tag = Tag::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->post(route('tasks.tags.store', $task), ['tag' => $tag->id])
        ->assertRedirect();

    expect($task->tags()->count())->toBe(1);

    $this->actingAs($actor)
        ->delete(route('tasks.tags.destroy', [$task, $tag]))
        ->assertRedirect();

    expect($task->tags()->count())->toBe(0);
});

it('refuses to put a tag from another workspace on a task', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $elsewhere = Tag::factory()->create();

    // A 404 before the Action has to refuse it — and the Action still refuses, for callers that
    // never pass through here.
    $this->actingAs($actor)
        ->post(route('tasks.tags.store', $task), ['tag' => $elsewhere->id])
        ->assertNotFound();

    expect($task->tags()->count())->toBe(0);
});

it('refuses tagging to somebody who may not edit the task', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $task = Task::factory()->in($workspace)->create();
    $tag = Tag::factory()->in($workspace)->create();

    $this->actingAs($guest)
        ->post(route('tasks.tags.store', $task), ['tag' => $tag->id])
        ->assertForbidden();
});

it('turns away everybody who is not signed in', function (): void {
    $workspace = Workspace::factory()->create();
    $tag = Tag::factory()->in($workspace)->create();
    $task = Task::factory()->in($workspace)->create();

    $this->post(route('tags.store'), ['name' => 'Bug'])->assertRedirect(route('login'));
    $this->put(route('tags.update', $tag), ['name' => 'Bug'])->assertRedirect(route('login'));
    $this->delete(route('tags.destroy', $tag))->assertRedirect(route('login'));
    $this->post(route('tasks.tags.store', $task), ['tag' => $tag->id])->assertRedirect(route('login'));
});
