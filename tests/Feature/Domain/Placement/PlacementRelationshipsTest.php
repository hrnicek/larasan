<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\LazyLoadingViolationException;

/**
 * @return array{Workspace, Project, Section}
 */
function board(): array
{
    $workspace = Workspace::factory()->create();
    $project = Project::factory()->in($workspace)->create();

    return [$workspace, $project, Section::factory()->in($project)->create()];
}

it('reads one task in two projects as one task', function (): void {
    [$workspace, $project] = board();
    $other = Project::factory()->in($workspace)->create(['name' => 'Alpha']);
    $project->update(['name' => 'Zulu']);
    $task = Task::factory()->in($workspace)->create();

    TaskProjectMembership::factory()->placing($task, $project)->create();
    TaskProjectMembership::factory()->placing($task, $other)->create();

    expect($task->placements()->count())->toBe(2)
        ->and($task->projects()->pluck('name')->all())->toBe(['Alpha', 'Zulu'])
        ->and(Task::query()->count())->toBe(1);
});

it("reads a project's tasks without copying them", function (): void {
    [$workspace, $project] = board();
    $task = Task::factory()->in($workspace)->create(['title' => 'Shared']);
    TaskProjectMembership::factory()->placing($task, $project)->create();

    $held = $project->tasks()->sole();

    expect($held->is($task))->toBeTrue()
        ->and($held->workspace_id)->toBe($workspace->id);
});

it('draws a column in position order, not insertion order', function (): void {
    [$workspace, $project, $section] = board();

    foreach (['Third' => 3, 'First' => 1, 'Second' => 2] as $title => $slot) {
        $task = Task::factory()->in($workspace)->create(['title' => $title]);

        TaskProjectMembership::factory()
            ->placing($task, $project)
            ->inSection($section)
            ->at($slot * TaskProjectMembership::POSITION_GAP)
            ->create();
    }

    expect($section->placements->load('task')->pluck('task.title')->all())
        ->toBe(['First', 'Second', 'Third']);
});

it("keeps another project's cards out of a column", function (): void {
    [$workspace, $project, $section] = board();
    $elsewhere = Project::factory()->in($workspace)->create();
    $mine = Task::factory()->in($workspace)->create();
    $theirs = Task::factory()->in($workspace)->create();

    TaskProjectMembership::factory()->placing($mine, $project)->inSection($section)->create();
    TaskProjectMembership::factory()->placing($theirs, $elsewhere)->create();

    expect($section->placements()->pluck('task_id')->all())->toBe([$mine->id])
        ->and($project->placements()->count())->toBe(1);
});

it('holds the ungrouped bucket in the project and in no column', function (): void {
    [$workspace, $project, $section] = board();
    $grouped = Task::factory()->in($workspace)->create();
    $ungrouped = Task::factory()->in($workspace)->create();

    TaskProjectMembership::factory()->placing($grouped, $project)->inSection($section)->create();
    TaskProjectMembership::factory()->placing($ungrouped, $project)->create();

    expect($project->placements()->count())->toBe(2)
        ->and($section->placements()->count())->toBe(1)
        ->and($project->placements()->whereNull('section_id')->pluck('task_id')->all())
        ->toBe([$ungrouped->id]);
});

it('eager loads a board without a query per card', function (): void {
    [$workspace, $project, $section] = board();

    foreach (range(1, 3) as $slot) {
        $task = Task::factory()->in($workspace)->create();

        TaskProjectMembership::factory()
            ->placing($task, $project)
            ->inSection($section)
            ->at($slot * TaskProjectMembership::POSITION_GAP)
            ->create();
    }

    /** @var Collection<int, TaskProjectMembership> $placements */
    $placements = $project->placements()->with(['task', 'section'])->get();

    // Builder::hydrate() only arms the lazy-loading guard for more than one row, so avoid first().
    expect($placements)->toHaveCount(3)
        ->and($placements->pluck('task.id')->filter())->toHaveCount(3)
        ->and($placements->pluck('section.name')->unique()->all())->toBe([$section->name]);
});

it("refuses to lazy load a placement's task", function (): void {
    [$workspace, $project] = board();

    foreach (range(1, 2) as $slot) {
        $task = Task::factory()->in($workspace)->create();

        TaskProjectMembership::factory()
            ->placing($task, $project)
            ->at($slot * TaskProjectMembership::POSITION_GAP)
            ->create();
    }

    $placements = $project->placements()->get();

    expect(fn (): mixed => $placements->first()?->task->title)
        ->toThrow(LazyLoadingViolationException::class);
});

it('loses its placements when the project goes and keeps the task', function (): void {
    [$workspace, $project] = board();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    $project->forceDelete();

    expect($task->placements()->count())->toBe(0)
        ->and($task->fresh())->not->toBeNull();
});
