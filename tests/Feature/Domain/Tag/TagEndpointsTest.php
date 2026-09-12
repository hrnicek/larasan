<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Inertia\Testing\AssertableInertia;

it('renders the workspace vocabulary with what each tag would cost to delete', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    $bug = Tag::factory()->in($workspace)->named('Bug')->create(['color' => ProjectColor::Rose]);
    Tag::factory()->in($workspace)->named('Chore')->create();

    $task = Task::factory()->in($workspace)->create();
    tagTask($task, $bug, $owner);

    $this->actingAs($owner)
        ->get(route('tags.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Tags')
            // Ordered by name.
            ->where('tags.0.name', 'Bug')
            ->where('tags.0.color', ProjectColor::Rose->value)
            ->where('tags.0.taskCount', 1)
            ->where('tags.1.name', 'Chore')
            ->where('tags.1.taskCount', 0)
            ->where('can.manage', true),
        );
});

it('shows somebody without tag.manage the list and none of the controls', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    Tag::factory()->in($workspace)->named('Bug')->create();

    $this->actingAs($guest)
        ->get(route('tags.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tags.0.name', 'Bug')
            ->where('can.manage', false),
        );
});

it('never shows another workspace its tags', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    Tag::factory()->in($workspace)->named('Mine')->create();
    Tag::factory()->named('Theirs')->create();

    $this->actingAs($owner)
        ->get(route('tags.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tags', 1)
            ->where('tags.0.name', 'Mine'),
        );
});

it('creates a tag', function (): void {
    [$workspace, , $actor] = placeableProject();

    $this->actingAs($actor)
        ->post(route('tags.store'), ['name' => '  Bug  ', 'color' => ProjectColor::Rose->value])
        ->assertRedirect();

    $tag = Tag::query()->sole();

    expect($tag->name)->toBe('Bug')
        ->and($tag->color?->paletteColor())->toBe(ProjectColor::Rose)
        ->and($tag->workspace_id)->toBe($workspace->id);
});

it('refuses a second tag with the same name in any case', function (): void {
    [$workspace, , $actor] = placeableProject();
    Tag::factory()->in($workspace)->named('Bug')->create();

    $this->actingAs($actor)
        ->from(route('dashboard'))
        ->post(route('tags.store'), ['name' => 'bug'])
        ->assertSessionHasErrors('name');

    expect(Tag::query()->count())->toBe(1);
});

it('refuses somebody without tag.manage', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);

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
        ->and($tag->fresh()?->color?->paletteColor())->toBe(ProjectColor::Amber);

    $this->actingAs($actor)->put(route('tags.update', $tag), ['color' => ProjectColor::Teal->value])->assertRedirect();
    expect($tag->fresh()?->color?->paletteColor())->toBe(ProjectColor::Teal)
        ->and($tag->fresh()?->name)->toBe('Bug');

    // An explicit null clears it; an absent key leaves it alone.
    $this->actingAs($actor)->put(route('tags.update', $tag), ['color' => null])->assertRedirect();
    expect($tag->fresh()?->color)->toBeNull();
});

it('takes a colour the palette does not have, and refuses one that is neither', function (): void {
    [$workspace, , $actor] = placeableProject();
    $tag = Tag::factory()->in($workspace)->named('Bug')->create();

    $this->actingAs($actor)->put(route('tags.update', $tag), ['color' => '#3F7D5A'])->assertRedirect();

    // Hex colours are normalised to lower case. See ADR-0021.
    expect($tag->fresh()?->color?->value)->toBe('#3f7d5a');

    $this->actingAs($actor)
        ->from(route('tags.index'))
        ->put(route('tags.update', $tag), ['color' => 'burgundy'])
        ->assertSessionHasErrors('color');

    expect($tag->fresh()?->color?->value)->toBe('#3f7d5a');
});

it('invents a tag with a chosen colour from the task it is for', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->post(route('tasks.tags.store', $task), ['name' => 'Chosen', 'color' => '#aabbcc'])
        ->assertRedirect();

    expect(Tag::query()->sole()->color?->value)->toBe('#aabbcc');
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

it('invents a tag and puts it on the task in one request', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->post(route('tasks.tags.store', $task), ['name' => '  Bug  ', 'color' => ProjectColor::Rose->value])
        ->assertRedirect();

    $tag = Tag::query()->sole();

    expect($tag->name)->toBe('Bug')
        ->and($tag->color?->paletteColor())->toBe(ProjectColor::Rose)
        ->and($tag->workspace_id)->toBe($workspace->id)
        ->and($task->tags()->count())->toBe(1);
});

it('attaches the tag a name already belongs to rather than refusing it as a duplicate', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $bug = Tag::factory()->in($workspace)->named('Bug')->create();

    $this->actingAs($actor)
        ->post(route('tasks.tags.store', $task), ['name' => 'bug'])
        ->assertRedirect();

    expect(Tag::query()->count())->toBe(1)
        ->and($task->tags()->sole()->id)->toBe($bug->id);
});

it('turns a guest away before a name can reach the vocabulary', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $task = Task::factory()->in($workspace)->create();

    // No role holds task update without tag.manage, so only this refusal is reachable over HTTP.
    $this->actingAs($guest)
        ->post(route('tasks.tags.store', $task), ['name' => 'Bug'])
        ->assertForbidden();

    expect(Tag::query()->count())->toBe(0);
});

it('needs either a tag or a name', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->from(route('dashboard'))
        ->post(route('tasks.tags.store', $task), [])
        ->assertSessionHasErrors('tag');

    expect($task->tags()->count())->toBe(0);
});

it('refuses to put a tag from another workspace on a task', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $elsewhere = Tag::factory()->create();

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
