<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Placement\Queries\ChannelsForTask;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Broadcasting\ViewInvalidated;
use App\Domain\Task\Events\TaskUpdated;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Event;

/**
 * @return list<string>
 */
function channelsFor(Task $task): array
{
    return app(ChannelsForTask::class)($task->id, $task->workspace_id);
}

it('announces an unplaced subtask on the channels of its placed parent, never on the workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $private = Project::factory()->in($workspace)->private()->create();
    $parent = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($parent, $private)->create();
    $subtask = Task::factory()->childOf($parent)->create();

    Event::fake([ViewInvalidated::class]);

    event(new TaskUpdated($subtask->id, $workspace->id, ['title'], $actor->id));

    Event::assertDispatched(ViewInvalidated::class, fn (ViewInvalidated $broadcast): bool => $broadcast->subjectId === $subtask->id
        && $broadcast->channels === ["project.{$private->id}"]);
});

it('walks up a chain of unplaced subtasks to the nearest placed ancestor', function (): void {
    $workspace = Workspace::factory()->create();
    $first = Project::factory()->in($workspace)->private()->create();
    $second = Project::factory()->in($workspace)->create();

    $root = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($root, $first)->create();
    $middle = Task::factory()->childOf($root)->create();
    TaskProjectMembership::factory()->placing($middle, $second)->create();
    $leaf = Task::factory()->childOf(Task::factory()->childOf($middle)->create())->create();

    expect(channelsFor($leaf))->toBe(["project.{$second->id}"]);
});

it('announces work under an unplaced root on the workspace channel', function (): void {
    $workspace = Workspace::factory()->create();
    $root = Task::factory()->in($workspace)->create();
    $subtask = Task::factory()->childOf($root)->create();

    expect(channelsFor($root))->toBe(["workspace.{$workspace->id}"])
        ->and(channelsFor($subtask))->toBe(["workspace.{$workspace->id}"]);
});

it('announces a task caught in a parent loop nowhere', function (): void {
    $workspace = Workspace::factory()->create();
    $first = Task::factory()->in($workspace)->create();
    $second = Task::factory()->childOf($first)->create();
    $first->forceFill(['parent_id' => $second->id])->save();

    expect(channelsFor($first))->toBe([]);
});

it('keeps announcing a placed task on its own projects only', function (): void {
    $workspace = Workspace::factory()->create();
    $open = Project::factory()->in($workspace)->create();
    $private = Project::factory()->in($workspace)->private()->create();
    $parent = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($parent, $open)->create();
    $child = Task::factory()->childOf($parent)->create();
    TaskProjectMembership::factory()->placing($child, $private)->create();

    expect(channelsFor($child))->toBe(["project.{$private->id}"]);
});
