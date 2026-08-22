<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('reads all three ends of a placement', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $project = Project::factory()->in($workspace)->create();
    $section = Section::factory()->in($project)->create();

    $placement = TaskProjectMembership::factory()
        ->placing($task, $project)
        ->inSection($section)
        ->create();

    expect($placement->task->is($task))->toBeTrue()
        ->and($placement->project->is($project))->toBeTrue()
        ->and($placement->section?->is($section))->toBeTrue();
});

it('gives both ends the same workspace by default', function (): void {
    $placement = TaskProjectMembership::factory()->create();

    // A factory that made two unrelated workspaces would hand every later test a row the
    // Actions are about to refuse to create (ADR-0003).
    expect($placement->task->workspace_id)->toBe($placement->project->workspace_id);
});

it('reads a factory placement without touching an attribute it never loaded', function (): void {
    $placement = TaskProjectMembership::factory()->create();

    expect($placement->section_id)->toBeNull()
        ->and($placement->section)->toBeNull()
        ->and($placement->position)->toBe(TaskProjectMembership::POSITION_GAP);
});

it('starts a placement ungrouped', function (): void {
    $ungrouped = TaskProjectMembership::factory()->create();
    $section = Section::factory()->create();
    $grouped = TaskProjectMembership::factory()->inSection($section)->create();

    // Attaching a task to a project puts it in the project, not in a column.
    expect($ungrouped->isUngrouped())->toBeTrue()
        ->and($grouped->isUngrouped())->toBeFalse();
});

it('keys itself with a uuid and reads the position back as an integer', function (): void {
    $placement = TaskProjectMembership::factory()->at(3 * TaskProjectMembership::POSITION_GAP)->create();

    expect(Str::isUuid($placement->id))->toBeTrue()
        ->and($placement->getIncrementing())->toBeFalse()
        ->and($placement->position)->toBe(196608);
});

it('refuses a position from mass assignment', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $project = Project::factory()->in($workspace)->create();

    $fill = fn (): TaskProjectMembership => (new TaskProjectMembership)->fill([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'section_id' => null,
        'position' => 42,
    ]);

    /*
     * A client never sends a position (ADR-0009): a move says "place this after that one".
     * Strict Eloquent makes the attempt an exception rather than a silently dropped
     * attribute, so a payload that reached this far is a bug with a stack trace.
     */
    expect($fill)->toThrow(MassAssignmentException::class);

    $placement = (new TaskProjectMembership)->fill([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'section_id' => null,
    ]);

    expect($placement->task_id)->toBe($task->id)
        ->and($placement->getAttributes())->not->toHaveKey('position');
});

it('does not soft delete', function (): void {
    $placement = TaskProjectMembership::factory()->create();

    $placement->delete();

    /*
     * A hidden row would still hold `UNIQUE(task_id, project_id)` and its slot, so
     * re-attaching a task to a project it was detached from would collide with its own
     * tombstone.
     */
    expect(DB::table('task_project_memberships')->count())->toBe(0)
        ->and(Task::query()->count())->toBe(1);
});

it('is scoped by joining the aggregates rather than by a column of its own', function (): void {
    $placement = TaskProjectMembership::factory()->create();

    // The rule in `docs/architecture/database.md`: a pure relationship table carries no
    // `workspace_id`, because the copy would be the one that drifts.
    expect($placement->getAttributes())->not->toHaveKey('workspace_id');
});
