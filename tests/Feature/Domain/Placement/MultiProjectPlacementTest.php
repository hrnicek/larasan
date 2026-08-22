<?php

declare(strict_types=1);

use App\Domain\Placement\Data\PlacementTarget;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Actions\CompleteTask;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/**
 * One task on two boards. The whole of ADR-0003 is here: what is shared is the task, what
 * differs is the placement.
 *
 * @return array{Task, Project, Project, User, Workspace}
 */
function taskOnTwoBoards(): array
{
    [$workspace, $first, $actor] = placeableProject();
    $second = Project::factory()->in($workspace)->create();
    ProjectMembership::factory()->in($second)->forUser($actor)->withAccess(ProjectAccessLevel::Editor)->create();

    $task = Task::factory()->in($workspace)->create(['title' => 'Shared']);

    attach($task, $first, $actor);
    attach($task, $second, $actor);

    return [$task, $first, $second, $actor, $workspace];
}

it('is one task and two placements, never two tasks', function (): void {
    [$task, $first, $second] = taskOnTwoBoards();

    expect(Task::query()->count())->toBe(1)
        ->and($task->placements()->count())->toBe(2)
        ->and($first->tasks()->sole()->id)->toBe($task->id)
        ->and($second->tasks()->sole()->id)->toBe($task->id);
});

it('completes the task everywhere at once', function (): void {
    [$task, $first, $second, $actor] = taskOnTwoBoards();

    app(CompleteTask::class)->complete($task, $actor);

    // Completion is `tasks.completed_at` and nothing else (ADR-0004): there is no per-board
    // notion of done, so the same card cannot be open on one board and closed on another.
    expect($first->tasks()->sole()->completed_at)->not->toBeNull()
        ->and($second->tasks()->sole()->completed_at)->not->toBeNull();
});

it('renames the task on both boards', function (): void {
    [$task, $first, $second] = taskOnTwoBoards();

    $task->forceFill(['title' => 'Renamed'])->save();

    expect($first->tasks()->sole()->title)->toBe('Renamed')
        ->and($second->tasks()->sole()->title)->toBe('Renamed');
});

it('keeps a separate column per project', function (): void {
    [$task, $first, $second, $actor] = taskOnTwoBoards();
    $here = Section::factory()->in($first)->create(['name' => 'Doing']);
    $there = Section::factory()->in($second)->create(['name' => 'Review']);

    moveTo($task->placements()->where('project_id', $first->id)->sole(), $actor, $here, PlacementTarget::end());
    moveTo($task->placements()->where('project_id', $second->id)->sole(), $actor, $there, PlacementTarget::end());

    expect($here->placements()->count())->toBe(1)
        ->and($there->placements()->count())->toBe(1)
        ->and($task->placements()->pluck('section_id')->sort()->values()->all())
        ->toBe(collect([$here->id, $there->id])->sort()->values()->all());
});

it('keeps a separate position per project', function (): void {
    [$task, $first, $second, $actor, $workspace] = taskOnTwoBoards();

    // A card at the front of one board and behind a neighbour on the other.
    $neighbour = attach(Task::factory()->in($workspace)->create(), $second, $actor);
    moveTo($task->placements()->where('project_id', $second->id)->sole(), $actor, null, PlacementTarget::after($neighbour));

    $here = $task->placements()->where('project_id', $first->id)->sole();
    $there = $task->placements()->where('project_id', $second->id)->sole();

    expect($here->position)->toBe(SparsePosition::GAP)
        ->and($there->position)->toBeGreaterThan($neighbour->refresh()->position);
});

it('moves the card on one board and leaves the other where it was', function (): void {
    [$task, $first, $second, $actor, $workspace] = taskOnTwoBoards();
    $column = Section::factory()->in($first)->create();
    $neighbour = attach(Task::factory()->in($workspace)->create(), $second, $actor);

    $here = $task->placements()->where('project_id', $first->id)->sole();
    $there = $task->placements()->where('project_id', $second->id)->sole();

    moveTo($here, $actor, $column, PlacementTarget::end());

    // A drag is a change to one placement. Anything that reached the task itself would move
    // the card on every board the task appears on.
    expect($there->refresh()->section_id)->toBeNull()
        ->and($there->position)->toBeLessThan($neighbour->refresh()->position)
        ->and($here->refresh()->section_id)->toBe($column->id);
});

it('leaves the other board alone when the task is detached from one', function (): void {
    [$task, $first, $second, $actor] = taskOnTwoBoards();

    detach($task, $first, $actor);

    expect($task->fresh())->not->toBeNull()
        ->and($first->placements()->count())->toBe(0)
        ->and($second->placements()->count())->toBe(1)
        // Still a workspace task with a place to be: My Tasks and search read tasks, not
        // placements (ADR-0003).
        ->and($task->placements()->count())->toBe(1);
});

it('leaves a task with no board at all still a task', function (): void {
    [$task, $first, $second, $actor] = taskOnTwoBoards();

    detach($task, $first, $actor);
    detach($task, $second, $actor);

    expect(Task::query()->whereKey($task->id)->exists())->toBeTrue()
        ->and($task->placements()->count())->toBe(0);
});

it('deletes only its own placements when one project is deleted', function (): void {
    [$task, $first, $second] = taskOnTwoBoards();

    $first->forceDelete();

    expect($task->fresh())->not->toBeNull()
        ->and($task->placements()->pluck('project_id')->all())->toBe([$second->id]);
});

it('takes both placements with the task when the task is deleted', function (): void {
    [$task, $first, $second] = taskOnTwoBoards();

    $task->forceDelete();

    expect(TaskProjectMembership::query()->count())->toBe(0)
        ->and(Project::query()->whereKey([$first->id, $second->id])->count())->toBe(2);
});
