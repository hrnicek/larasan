<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Actions\AssignTask;
use App\Domain\Task\Events\TaskAssigned;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Event;

it('assigns the task to a member of its workspace', function (): void {
    [$task, $actor] = taskEditableBy();
    $assignee = memberOf($task->workspace, WorkspaceRole::Member);

    app(AssignTask::class)->handle($task, $actor, $assignee);

    expect($task->fresh()?->assignee_id)->toBe($assignee->id);
});

it('unassigns when nobody is given', function (): void {
    [$task, $actor] = taskEditableBy();
    $assignee = memberOf($task->workspace, WorkspaceRole::Member);
    app(AssignTask::class)->handle($task, $actor, $assignee);

    app(AssignTask::class)->handle($task->fresh(), $actor, null);

    expect($task->fresh()?->assignee_id)->toBeNull();
});

it('announces the assignment and the unassignment', function (): void {
    [$task, $actor] = taskEditableBy();
    $assignee = memberOf($task->workspace, WorkspaceRole::Member);
    Event::fake();

    app(AssignTask::class)->handle($task, $actor, $assignee);
    Event::assertDispatched(TaskAssigned::class, fn (TaskAssigned $event): bool => $event->assigneeId === $assignee->id
        && $event->assignedById === $actor->id);

    app(AssignTask::class)->handle($task->fresh(), $actor, null);
    Event::assertDispatched(TaskAssigned::class, fn (TaskAssigned $event): bool => $event->assigneeId === null);
});

it('stays quiet when the assignment does not change', function (): void {
    [$task, $actor] = taskEditableBy();
    $assignee = memberOf($task->workspace, WorkspaceRole::Member);
    app(AssignTask::class)->handle($task, $actor, $assignee);
    Event::fake();

    app(AssignTask::class)->handle($task->fresh(), $actor, $assignee);
    Event::assertNotDispatched(TaskAssigned::class);

    app(AssignTask::class)->handle($task->fresh(), $actor, null);
    app(AssignTask::class)->handle($task->fresh(), $actor, null);
    Event::assertDispatchedTimes(TaskAssigned::class, 1);
});

it('refuses an assignee whose membership is not active', function (WorkspaceMembershipStatus $status): void {
    [$task, $actor] = taskEditableBy();
    $candidate = memberOf($task->workspace, WorkspaceRole::Member, $status);

    expect(fn (): Task => app(AssignTask::class)->handle($task, $actor, $candidate))
        ->toThrow(TaskException::class, 'active member');

    expect($task->fresh()?->assignee_id)->toBeNull();
})->with([
    'invited' => WorkspaceMembershipStatus::Invited,
    'declined' => WorkspaceMembershipStatus::Declined,
    'revoked' => WorkspaceMembershipStatus::Revoked,
    'expired' => WorkspaceMembershipStatus::Expired,
]);

it('refuses an account from another workspace', function (): void {
    [$task, $actor] = taskEditableBy();
    $stranger = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    // A user id is global. Without this check any account in the installation could be
    // handed work inside a tenant it has never been part of.
    expect(fn (): Task => app(AssignTask::class)->handle($task, $actor, $stranger))
        ->toThrow(TaskException::class, 'active member');
});

it('refuses an account with no membership at all', function (): void {
    [$task, $actor] = taskEditableBy();

    expect(fn (): Task => app(AssignTask::class)->handle($task, $actor, User::factory()->create()))
        ->toThrow(TaskException::class);
});

it('refuses a guest who cannot reach the task', function (): void {
    [$task, $actor] = taskEditableBy();
    $guest = memberOf($task->workspace, WorkspaceRole::Guest);

    /*
     * The question TASK-060-012 deferred until a task had places, answered in
     * TASK-070-017: a guest holds the projects they were given, and this task is in none of
     * them. Work nobody can open is not work anybody can do.
     */
    expect(fn (): Task => app(AssignTask::class)->handle($task, $actor, $guest))
        ->toThrow(TaskException::class, 'That person cannot reach this task.');

    expect($task->fresh()?->assignee_id)->toBeNull();
});

it('assigns a guest a task that is in a project they were given', function (): void {
    [$task, $actor] = taskEditableBy();
    $guest = memberOf($task->workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($task->workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Editor)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    app(AssignTask::class)->handle($task, $actor, $guest);

    expect($task->fresh()?->assignee_id)->toBe($guest->id);
});

it('refuses a member for a task that lives only in a private project they are not in', function (): void {
    [$task, $actor] = taskEditableBy();
    $outsider = memberOf($task->workspace, WorkspaceRole::Member);
    $private = Project::factory()->in($task->workspace)->create(['visibility' => ProjectVisibility::Private]);
    TaskProjectMembership::factory()->placing($task, $private)->create();

    // Being in the workspace is not being in the room: the card is on a board they cannot
    // open, so its title is not theirs to read either.
    expect(fn (): Task => app(AssignTask::class)->handle($task, $actor, $outsider))
        ->toThrow(TaskException::class, 'That person cannot reach this task.');
});

it('refuses an actor without the task.assign capability', function (): void {
    [$task, $guest] = taskEditableBy(WorkspaceRole::Guest);
    $assignee = memberOf($task->workspace, WorkspaceRole::Member);

    expect(fn (): Task => app(AssignTask::class)->handle($task, $guest, $assignee))
        ->toThrow(TaskException::class, 'permission to assign');

    expect($task->fresh()?->assignee_id)->toBeNull();
});

it('refuses an actor from another workspace', function (): void {
    [$task] = taskEditableBy();
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);
    $assignee = memberOf($task->workspace, WorkspaceRole::Member);

    expect(fn (): Task => app(AssignTask::class)->handle($task, $outsider, $assignee))
        ->toThrow(TaskException::class);
});
