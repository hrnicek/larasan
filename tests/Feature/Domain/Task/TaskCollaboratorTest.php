<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Broadcasting\ViewInvalidated;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Actions\AddTaskCollaborator;
use App\Domain\Task\Actions\AssignTask;
use App\Domain\Task\Actions\RemoveTaskCollaborator;
use App\Domain\Task\Events\TaskCollaboratorAdded;
use App\Domain\Task\Events\TaskCollaboratorRemoved;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskCollaborator;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * @return array{Task, User}
 */
function collaborationTask(WorkspaceRole $role = WorkspaceRole::Member): array
{
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);

    return [Task::factory()->in($workspace)->create(), $actor];
}

function collaborate(Task $task, User $actor, User $collaborator): TaskCollaborator
{
    return app(AddTaskCollaborator::class)->handle($task, $actor, $collaborator);
}

function stopCollaborating(Task $task, User $actor, User $collaborator): void
{
    app(RemoveTaskCollaborator::class)->handle($task, $actor, $collaborator->id);
}

it('puts several people on a task beside its assignee', function (): void {
    [$task, $actor] = collaborationTask();
    $assignee = memberOf($task->workspace);
    app(AssignTask::class)->handle($task, $actor, $assignee);
    $first = memberOf($task->workspace);
    $second = memberOf($task->workspace);

    collaborate($task, $actor, $first);
    collaborate($task, $actor, $second);

    expect($task->collaborators()->pluck('users.id')->all())->toEqualCanonicalizing([$first->id, $second->id])
        ->and($task->fresh()?->assignee_id)->toBe($assignee->id);
});

it('treats a second add as the same add, and announces only the first', function (): void {
    [$task, $actor] = collaborationTask();
    $collaborator = memberOf($task->workspace);
    Event::fake([TaskCollaboratorAdded::class]);

    $first = collaborate($task, $actor, $collaborator);
    $second = collaborate($task, $actor, $collaborator);

    expect($second->id)->toBe($first->id)
        ->and($task->collaborations()->count())->toBe(1);
    Event::assertDispatchedTimes(TaskCollaboratorAdded::class, 1);
    Event::assertDispatched(TaskCollaboratorAdded::class, fn (TaskCollaboratorAdded $event): bool => $event->taskId === $task->id
        && $event->workspaceId === $task->workspace_id
        && $event->collaboratorId === $collaborator->id
        && $event->addedById === $actor->id);
});

it('refuses somebody whose membership is not active', function (WorkspaceMembershipStatus $status): void {
    [$task, $actor] = collaborationTask();
    $candidate = memberOf($task->workspace, WorkspaceRole::Member, $status);

    expect(fn (): TaskCollaborator => collaborate($task, $actor, $candidate))
        ->toThrow(TaskException::class, 'active member');

    expect($task->collaborations()->count())->toBe(0);
})->with([
    'invited' => WorkspaceMembershipStatus::Invited,
    'declined' => WorkspaceMembershipStatus::Declined,
    'revoked' => WorkspaceMembershipStatus::Revoked,
    'expired' => WorkspaceMembershipStatus::Expired,
]);

it('refuses somebody from another workspace', function (): void {
    [$task, $actor] = collaborationTask();
    $stranger = memberOf(Workspace::factory()->create());

    expect(fn (): TaskCollaborator => collaborate($task, $actor, $stranger))
        ->toThrow(TaskException::class, 'active member');

    expect($task->collaborations()->count())->toBe(0);
});

it('refuses a member who cannot open the task', function (): void {
    [$task, $actor] = collaborationTask();
    $private = Project::factory()->in($task->workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($private)->forUser($actor)->withAccess(ProjectAccessLevel::Editor)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();
    $outsider = memberOf($task->workspace, WorkspaceRole::Member);

    expect(fn (): TaskCollaborator => collaborate($task, $actor, $outsider))
        ->toThrow(TaskException::class, 'cannot reach this task');

    expect($task->collaborations()->count())->toBe(0);
});

it('refuses the assignee as a collaborator on their own task', function (): void {
    [$task, $actor] = collaborationTask();
    $assignee = memberOf($task->workspace);
    app(AssignTask::class)->handle($task, $actor, $assignee);

    expect(fn (): TaskCollaborator => collaborate($task->refresh(), $actor, $assignee))
        ->toThrow(TaskException::class, 'already assigned');

    expect($task->collaborations()->count())->toBe(0);
});

it('refuses an actor who may not assign', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $task = Task::factory()->in($workspace)->create();
    $collaborator = memberOf($workspace);

    expect(fn (): TaskCollaborator => collaborate($task, $guest, $collaborator))
        ->toThrow(TaskException::class, 'permission to assign');

    expect($task->collaborations()->count())->toBe(0);
});

it('takes a collaborator off the list when the task is handed to them', function (): void {
    [$task, $actor] = collaborationTask();
    $collaborator = memberOf($task->workspace);
    $other = memberOf($task->workspace);
    collaborate($task, $actor, $collaborator);
    collaborate($task, $actor, $other);

    app(AssignTask::class)->handle($task, $actor, $collaborator);

    expect($task->fresh()?->assignee_id)->toBe($collaborator->id)
        ->and($task->collaborators()->pluck('users.id')->all())->toBe([$other->id]);
});

it('makes a new collaborator watch the task', function (): void {
    [$task, $actor] = collaborationTask();
    $collaborator = memberOf($task->workspace);

    collaborate($task, $actor, $collaborator);

    expect($task->followers()->pluck('users.id')->all())->toContain($collaborator->id);
});

it('records both directions in the task history', function (): void {
    [$task, $actor] = collaborationTask();
    $collaborator = memberOf($task->workspace);

    collaborate($task, $actor, $collaborator);
    stopCollaborating($task, $actor, $collaborator);

    $lines = DB::table('activities')
        ->whereIn('type', ['task.collaborator_added', 'task.collaborator_removed'])
        ->get(['type', 'actor_id', 'properties']);

    expect($lines->pluck('type')->all())->toEqualCanonicalizing(['task.collaborator_added', 'task.collaborator_removed'])
        ->and($lines->pluck('actor_id')->unique()->all())->toBe([$actor->id])
        ->and($lines->map(fn (object $line): mixed => json_decode((string) $line->properties, true)['collaborator_id'])->unique()->all())
        ->toBe([$collaborator->id]);
});

it('takes somebody off, and stays quiet when they were not on it', function (): void {
    [$task, $actor] = collaborationTask();
    $collaborator = memberOf($task->workspace);
    collaborate($task, $actor, $collaborator);
    Event::fake([TaskCollaboratorRemoved::class]);

    stopCollaborating($task, $actor, $collaborator);
    stopCollaborating($task, $actor, $collaborator);

    expect($task->collaborations()->count())->toBe(0);
    Event::assertDispatchedTimes(TaskCollaboratorRemoved::class, 1);
    Event::assertDispatched(TaskCollaboratorRemoved::class, fn (TaskCollaboratorRemoved $event): bool => $event->collaboratorId === $collaborator->id
        && $event->removedById === $actor->id);
});

it('lets a collaborator step off without the right to assign, and nobody else', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create();
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Viewer)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();
    $colleague = memberOf($workspace, WorkspaceRole::Member);
    collaborate($task, $actor, $guest);
    collaborate($task, $actor, $colleague);

    expect(fn () => stopCollaborating($task, $guest, $colleague))
        ->toThrow(TaskException::class, 'permission to assign');

    stopCollaborating($task, $guest, $guest);

    expect($task->collaborators()->pluck('users.id')->all())->toBe([$colleague->id]);
});

it('tells the screens showing the task, both ways', function (): void {
    [$task, $actor] = collaborationTask();
    $collaborator = memberOf($task->workspace);
    Event::fake([ViewInvalidated::class]);

    collaborate($task, $actor, $collaborator);
    stopCollaborating($task, $actor, $collaborator);

    foreach (['task.collaborator_added', 'task.collaborator_removed'] as $change) {
        Event::assertDispatched(ViewInvalidated::class, fn (ViewInvalidated $event): bool => $event->change === $change
            && $event->subjectId === $task->id
            && $event->actorId === $actor->id);
    }
});
