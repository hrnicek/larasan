<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;

it('requires authentication', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    $this->post(route('placements.store', $project), ['task' => $placement->task_id])
        ->assertRedirect(route('login'));
    $this->put(route('placements.move', $placement), ['section' => null])
        ->assertRedirect(route('login'));
    $this->delete(route('placements.destroy', $placement))
        ->assertRedirect(route('login'));
});

it('attaches a task to a project', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->from(route('dashboard'))
        ->post(route('placements.store', $project), ['task' => $task->id])
        ->assertRedirect(route('dashboard'));

    expect($project->placements()->sole()->task_id)->toBe($task->id);
});

it('moves a card into a column and back out of it', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    $this->actingAs($actor)
        ->put(route('placements.move', $placement), ['section' => $section->id])
        ->assertRedirect();

    expect($placement->refresh()->section_id)->toBe($section->id);

    $this->actingAs($actor)
        ->put(route('placements.move', $placement), ['section' => null])
        ->assertRedirect();

    expect($placement->refresh()->section_id)->toBeNull();
});

it('reorders a column through an anchor rather than a position', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();

    $cards = collect(['A', 'B', 'C'])->map(function (string $title) use ($workspace, $project, $actor, $section): TaskProjectMembership {
        $placement = attach(Task::factory()->in($workspace)->create(['title' => $title]), $project, $actor);
        moveInto($placement, $actor, $section);

        return $placement->refresh();
    });

    $this->actingAs($actor)
        ->put(route('placements.move', $cards[2]), ['section' => $section->id, 'after' => $cards[0]->id])
        ->assertRedirect();

    expect(orderIn($section))->toBe(['A', 'C', 'B']);
});

it('detaches a card and keeps the task', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $placement = attach($task, $project, $actor);

    $this->actingAs($actor)
        ->delete(route('placements.destroy', $placement))
        ->assertRedirect();

    expect($project->placements()->count())->toBe(0)
        ->and($task->fresh())->not->toBeNull();
});

it('hides a card in another workspace behind a 404', function (): void {
    [, , $actor] = placeableProject();
    [$otherWorkspace, $otherProject, $otherActor] = placeableProject();
    $elsewhere = attach(Task::factory()->in($otherWorkspace)->create(), $otherProject, $otherActor);

    // A leaked id must not confirm that the card exists: 403 would (ADR-0005).
    $this->actingAs($actor)
        ->put(route('placements.move', $elsewhere), ['section' => null])
        ->assertNotFound();

    $this->actingAs($actor)
        ->delete(route('placements.destroy', $elsewhere))
        ->assertNotFound();
});

it('hides a card in a private project of the same workspace behind a 404', function (): void {
    [$workspace, , $actor] = placeableProject();
    $private = Project::factory()->in($workspace)->create(['visibility' => 'private']);
    $placement = TaskProjectMembership::factory()
        ->placing(Task::factory()->in($workspace)->create(), $private)
        ->create();

    // The binding resolves through the projects the actor can see, one level down from the
    // rule `routes/sections.php` binds with.
    $this->actingAs($actor)
        ->delete(route('placements.destroy', $placement))
        ->assertNotFound();
});

it('refuses a viewer with a 403 rather than a 404', function (): void {
    [$workspace, $project, $viewer] = placeableProject(ProjectAccessLevel::Viewer);
    $placement = TaskProjectMembership::factory()
        ->placing(Task::factory()->in($workspace)->create(), $project)
        ->create();

    // They can see the project, so the card is not a secret — only the change is refused.
    $this->actingAs($viewer)
        ->put(route('placements.move', $placement), ['section' => null])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->delete(route('placements.destroy', $placement))
        ->assertForbidden();
});

it('refuses to attach a task from another workspace', function (): void {
    [, $project, $actor] = placeableProject();
    $elsewhere = Task::factory()->in(Workspace::factory()->create())->create();

    $this->actingAs($actor)
        ->from(route('dashboard'))
        ->post(route('placements.store', $project), ['task' => $elsewhere->id])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors('task');

    expect($project->placements()->count())->toBe(0);
});
