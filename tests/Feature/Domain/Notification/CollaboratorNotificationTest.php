<?php

declare(strict_types=1);

use App\Domain\Notification\Listeners\NotifyNewCollaborator;
use App\Domain\Notification\Notifications\TaskCollaboratorAddedNotification;
use App\Domain\Task\Actions\AddTaskCollaborator;
use App\Domain\Task\Actions\RemoveTaskCollaborator;
use App\Domain\Task\Events\TaskCollaboratorAdded;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * @return list<object{workspace_id: string, data: string}>
 */
function collaboratorNoticesOf(User $user): array
{
    /** @var list<object{workspace_id: string, data: string}> $notices */
    $notices = array_values(DB::table('notifications')
        ->where('notifiable_type', 'user')
        ->where('notifiable_id', $user->id)
        ->where('type', TaskCollaboratorAddedNotification::class)
        ->get(['workspace_id', 'data'])
        ->all());

    return $notices;
}

it('tells somebody they were put on a task', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $collaborator = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    app(AddTaskCollaborator::class)->handle($task, $actor, $collaborator);

    $notices = collaboratorNoticesOf($collaborator);

    expect($notices)->toHaveCount(1)
        ->and($notices[0]->workspace_id)->toBe($workspace->id)
        ->and(json_decode($notices[0]->data, true, 512, JSON_THROW_ON_ERROR))
        ->toBe(['task_id' => $task->id, 'added_by_id' => $actor->id]);
});

it('says nothing to somebody who put themselves on a task', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    app(AddTaskCollaborator::class)->handle($task, $actor, $actor);

    expect(collaboratorNoticesOf($actor))->toBeEmpty();
});

it('tells nobody when somebody is taken off', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $collaborator = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    app(AddTaskCollaborator::class)->handle($task, $actor, $collaborator);
    app(RemoveTaskCollaborator::class)->handle($task, $actor, $collaborator->id);

    expect(collaboratorNoticesOf($collaborator))->toHaveCount(1)
        ->and(DB::table('notifications')->where('notifiable_id', $actor->id)->count())->toBe(0);
});

it('says nothing when they were taken off before the notice went out', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $collaborator = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    // Simulates the queued listener running after the collaborator was removed.
    app(NotifyNewCollaborator::class)->handle(
        new TaskCollaboratorAdded($task->id, $workspace->id, $collaborator->id, $actor->id),
    );

    expect(collaboratorNoticesOf($collaborator))->toBeEmpty();
});
