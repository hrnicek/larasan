<?php

declare(strict_types=1);

use App\Domain\Comment\Events\CommentCreated;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Broadcasting\ViewInvalidated;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Task\Events\TaskAssigned;
use App\Domain\Task\Events\TaskCompleted;
use App\Domain\Task\Events\TaskCreated;
use App\Domain\Task\Events\TaskDeleted;
use App\Domain\Task\Events\TaskReopened;
use App\Domain\Task\Events\TaskUpdated;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

/**
 * @return array{Workspace, Task, User}
 */
function looseTask(): array
{
    $workspace = Workspace::factory()->create();

    // A member, because the other listeners run for real and the domain refuses a stranger.
    $actor = memberOf($workspace);

    return [$workspace, Task::factory()->in($workspace)->create(), $actor];
}

function placedIn(Task $task, Project $project): void
{
    TaskProjectMembership::factory()->placing($task, $project)->create();
}

/**
 * @return list<string>
 */
function channelsOfLastBroadcast(): array
{
    $channels = [];

    Event::assertDispatched(ViewInvalidated::class, function (ViewInvalidated $event) use (&$channels): bool {
        $channels = $event->channels;

        return true;
    });

    return $channels;
}

it('announces a task that sits in no project on the workspace channel', function (): void {
    Event::fake([ViewInvalidated::class]);
    [$workspace, $task, $actor] = looseTask();

    event(new TaskUpdated($task->id, $workspace->id, ['title'], $actor->id));

    expect(channelsOfLastBroadcast())->toBe(["workspace.{$workspace->id}"]);
});

it('announces a placed task on its projects channels and never on the workspace', function (): void {
    Event::fake([ViewInvalidated::class]);
    [$workspace, $task, $actor] = looseTask();
    $project = Project::factory()->in($workspace)->create();
    placedIn($task, $project);

    event(new TaskUpdated($task->id, $workspace->id, ['title'], $actor->id));

    expect(channelsOfLastBroadcast())->toBe(["project.{$project->id}"]);
});

it('never puts a private projects task on the workspace channel', function (): void {
    Event::fake([ViewInvalidated::class]);
    [$workspace, $task, $actor] = looseTask();
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    placedIn($task, $private);

    event(new TaskUpdated($task->id, $workspace->id, ['title'], $actor->id));

    expect(channelsOfLastBroadcast())
        ->toBe(["project.{$private->id}"])
        ->not->toContain("workspace.{$workspace->id}");
})->with([
    'the id of a task in a project nobody was given would tell every member it exists, which
    is what the private project was for',
]);

it('announces a task in two projects on both, and only both', function (): void {
    Event::fake([ViewInvalidated::class]);
    [$workspace, $task, $actor] = looseTask();
    $first = Project::factory()->in($workspace)->create();
    $second = Project::factory()->in($workspace)->create();
    placedIn($task, $first);
    placedIn($task, $second);

    event(new TaskCompleted($task->id, $workspace->id, $actor->id));

    expect(channelsOfLastBroadcast())
        ->toHaveCount(2)
        ->toContain("project.{$first->id}")
        ->toContain("project.{$second->id}");
});

it('announces every task change a shared screen draws', function (string $eventClass, string $change): void {
    Event::fake([ViewInvalidated::class]);
    [$workspace, $task, $actor] = looseTask();

    $event = match ($eventClass) {
        TaskCreated::class => new TaskCreated($task->id, $workspace->id, $actor->id),
        TaskUpdated::class => new TaskUpdated($task->id, $workspace->id, ['title'], $actor->id),
        TaskCompleted::class => new TaskCompleted($task->id, $workspace->id, $actor->id),
        TaskReopened::class => new TaskReopened($task->id, $workspace->id, $actor->id),
        TaskAssigned::class => new TaskAssigned($task->id, $workspace->id, $actor->id, $actor->id),
        TaskDeleted::class => new TaskDeleted($task->id, $workspace->id, $actor->id),
        default => throw new InvalidArgumentException("the dataset names [{$eventClass}] and this test does not build it"),
    };

    event($event);

    Event::assertDispatched(
        ViewInvalidated::class,
        fn (ViewInvalidated $broadcast): bool => $broadcast->change === $change
            && $broadcast->subjectType === 'task'
            && $broadcast->subjectId === $task->id,
    );
})->with([
    'created' => [TaskCreated::class, 'task.created'],
    'updated' => [TaskUpdated::class, 'task.updated'],
    'completed' => [TaskCompleted::class, 'task.completed'],
    'reopened' => [TaskReopened::class, 'task.reopened'],
    'assigned' => [TaskAssigned::class, 'task.assigned'],
    'deleted' => [TaskDeleted::class, 'task.deleted'],
]);

it('still reaches the board when the task it describes is already deleted', function (): void {
    Event::fake([ViewInvalidated::class]);
    [$workspace, $task, $actor] = looseTask();
    $project = Project::factory()->in($workspace)->create();
    placedIn($task, $project);
    $task->delete();

    event(new TaskDeleted($task->id, $workspace->id, $actor->id));

    expect(channelsOfLastBroadcast())->toBe(["project.{$project->id}"]);
})->with([
    'deleting is soft and the placement outlives the task, so the column that has to lose the
    card is still knowable',
]);

it('announces a comment on the channels of the task it was left on', function (): void {
    Event::fake([ViewInvalidated::class]);
    $workspace = Workspace::factory()->create();
    $author = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();
    $project = Project::factory()->in($workspace)->create();
    placedIn($task, $project);

    event(new CommentCreated('01a00000-0000-7000-8000-000000000000', $workspace->id, 'task', $task->id, $author->id));

    Event::assertDispatched(
        ViewInvalidated::class,
        fn (ViewInvalidated $broadcast): bool => $broadcast->change === 'comment.created'
            && $broadcast->channels === ["project.{$project->id}"]
            && $broadcast->subjectId === $task->id,
    );
})->with([
    'the comment text is not in the payload: a channel is a wider audience than a thread, and
    the panel refetches anyway',
]);

it('carries ids and an actor, and nothing else', function (): void {
    Event::fake([ViewInvalidated::class]);
    [$workspace, $task, $actor] = looseTask();

    event(new TaskUpdated($task->id, $workspace->id, ['title', 'description'], $actor->id));

    Event::assertDispatched(ViewInvalidated::class, function (ViewInvalidated $broadcast) use ($task, $actor, $workspace): bool {
        expect($broadcast->broadcastWith())->toBe([
            'change' => 'task.updated',
            'subject' => ['type' => 'task', 'id' => $task->id],
            'actorId' => $actor->id,
        ]);

        expect($broadcast->broadcastOn())->toEqual([new PrivateChannel("workspace.{$workspace->id}")]);
        expect($broadcast->broadcastAs())->toBe('view.invalidated');
        expect($broadcast->broadcastQueue())->toBe('broadcasts');

        return true;
    });
})->with([
    'the changed field names are deliberately absent — a subscriber refetches, and a list of
    columns is a payload somebody has to prove is safe for everyone on the channel',
]);
