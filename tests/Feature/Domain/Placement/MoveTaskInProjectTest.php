<?php

declare(strict_types=1);

use App\Domain\Placement\Actions\MoveTaskInProject;
use App\Domain\Placement\Events\TaskPlacementMoved;
use App\Domain\Placement\Exceptions\PlacementException;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Event;

function moveInto(TaskProjectMembership $placement, User $actor, ?Section $section): TaskProjectMembership
{
    return app(MoveTaskInProject::class)->handle($placement, $actor, $section);
}

it('puts a card in a column', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    moveInto($placement, $actor, $section);

    expect($placement->fresh()?->section_id)->toBe($section->id)
        ->and($section->placements()->count())->toBe(1);
});

it('appends a card to the end of the column it joins', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();

    $positions = collect(range(1, 3))
        ->map(fn (): int => moveInto(
            attach(Task::factory()->in($workspace)->create(), $project, $actor),
            $actor,
            $section,
        )->refresh()->position)
        ->all();

    expect($positions)->toBe(SparsePosition::spread(3));
});

it('gives a card a new slot instead of carrying its old number over', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $from = Section::factory()->in($project)->at(SparsePosition::GAP)->create();
    $to = Section::factory()->in($project)->at(2 * SparsePosition::GAP)->create();

    $held = attach(Task::factory()->in($workspace)->create(), $project, $actor);
    moveInto($held, $actor, $to);

    $moving = attach(Task::factory()->in($workspace)->create(), $project, $actor);
    moveInto($moving, $actor, $from);

    moveInto($moving->fresh() ?? $moving, $actor, $to);

    expect($to->placements()->pluck('position')->all())
        ->toBe([SparsePosition::GAP, 2 * SparsePosition::GAP])
        ->and($from->placements()->count())->toBe(0);
});

it('drags a card out of a column into the ungrouped bucket', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);
    moveInto($placement, $actor, $section);

    moveInto($placement->fresh() ?? $placement, $actor, null);

    expect($placement->fresh()?->section_id)->toBeNull()
        ->and($project->placements()->count())->toBe(1)
        ->and($section->placements()->count())->toBe(0);
});

it('appends to the end of the ungrouped bucket on the way out', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();

    attach(Task::factory()->in($workspace)->create(), $project, $actor);
    $moving = attach(Task::factory()->in($workspace)->create(), $project, $actor);
    moveInto($moving, $actor, $section);

    moveInto($moving->fresh() ?? $moving, $actor, null);

    expect($project->placements()->whereNull('section_id')->pluck('position')->sort()->values()->all())
        ->toBe([SparsePosition::GAP, 2 * SparsePosition::GAP]);
});

it('refuses a column belonging to another project', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $elsewhere = Project::factory()->in($workspace)->create();
    $foreign = Section::factory()->in($elsewhere)->create();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    expect(fn (): TaskProjectMembership => moveInto($placement, $actor, $foreign))
        ->toThrow(PlacementException::class, 'That section is not in this project.');

    expect($placement->fresh()?->section_id)->toBeNull();
});

it('does nothing when the card is already in that column', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);
    $moved = moveInto($placement, $actor, $section);

    Event::fake();
    moveInto($moved->fresh() ?? $moved, $actor, $section);

    expect($placement->fresh()?->position)->toBe($moved->fresh()?->position);
    Event::assertNotDispatched(TaskPlacementMoved::class);
});

it('refuses an actor who may only view the project', function (): void {
    [$workspace, $project, $viewer] = placeableProject(ProjectAccessLevel::Viewer);
    $section = Section::factory()->in($project)->create();
    $placement = TaskProjectMembership::factory()
        ->placing(Task::factory()->in($workspace)->create(), $project)
        ->create();

    expect(fn (): TaskProjectMembership => moveInto($placement, $viewer, $section))
        ->toThrow(PlacementException::class, 'You do not have permission to change what this project holds.');

    expect($placement->fresh()?->section_id)->toBeNull();
});

it('refuses somebody from another workspace entirely', function (): void {
    [$workspace, $project] = placeableProject();
    $section = Section::factory()->in($project)->create();
    $placement = TaskProjectMembership::factory()
        ->placing(Task::factory()->in($workspace)->create(), $project)
        ->create();

    expect(fn (): TaskProjectMembership => moveInto($placement, memberOf(Workspace::factory()->create()), $section))
        ->toThrow(PlacementException::class);
});

it('announces the move, and says which column', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    Event::fake();
    moveInto($placement, $actor, $section);

    Event::assertDispatched(TaskPlacementMoved::class, fn (TaskPlacementMoved $event): bool => $event->placementId === $placement->id
        && $event->projectId === $project->id
        && $event->sectionId === $section->id
        && $event->movedById === $actor->id);
});

it('leaves the same task in another project alone', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $other = Project::factory()->in($workspace)->create();
    $section = Section::factory()->in($project)->create();
    $task = Task::factory()->in($workspace)->create();

    $here = attach($task, $project, $actor);
    $there = TaskProjectMembership::factory()->placing($task, $other)->create();

    moveInto($here, $actor, $section);

    expect($there->fresh()?->section_id)->toBeNull()
        ->and($there->fresh()?->position)->toBe($there->position);
});
