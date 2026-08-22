<?php

declare(strict_types=1);

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

it('allows a guest to be assigned, for now', function (): void {
    [$task, $actor] = taskEditableBy();
    $guest = memberOf($task->workspace, WorkspaceRole::Guest);

    app(AssignTask::class)->handle($task, $actor, $guest);

    /*
     * A guest is an active member, so nothing here refuses them. Whether a guest may hold
     * work they cannot reach only becomes answerable in Phase 070, when a task has places:
     * the rule is recorded on TASK-060-012 rather than guessed at now.
     */
    expect($task->fresh()?->assignee_id)->toBe($guest->id);
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
