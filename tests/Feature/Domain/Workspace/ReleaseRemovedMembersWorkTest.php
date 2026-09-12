<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskCollaborator;
use App\Domain\Workspace\Actions\RemoveWorkspaceMember;
use App\Domain\Workspace\Jobs\ReleaseRemovedMembersWork;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * @return array{Workspace, User, User, WorkspaceMembership}
 */
function memberHolding(int $tasks): array
{
    $workspace = Workspace::factory()->create();
    $owner = memberOf($workspace, WorkspaceRole::Owner);
    $leaving = memberOf($workspace, WorkspaceRole::Member);

    Task::factory()->count($tasks)->in($workspace)->create(['assignee_id' => $leaving->id]);

    $membership = $workspace->memberships()->where('user_id', $leaving->id)->firstOrFail();

    return [$workspace, $owner, $leaving, $membership];
}

// Queue::fake() also intercepts dispatchSync, so only a real queue tells queued work from inline work.
beforeEach(function (): void {
    config(['queue.default' => 'database']);
});

it('costs the same request whether they held five tasks or fifty', function (): void {
    $counts = [];

    foreach ([5, 50] as $tasks) {
        [$workspace, $owner, , $membership] = memberHolding($tasks);

        $counts[] = count(queriesWhile(function () use ($workspace, $owner, $membership): void {
            app(RemoveWorkspaceMember::class)->handle($workspace, $owner, $membership);
        }));
    }

    [$small, $large] = $counts;

    expect($large)->toBe($small, "removing somebody holding fifty tasks made {$large} queries and five made {$small}");
})->with([
    'it was four queries per task: twenty tasks were 86 and forty were 166',
]);

it('revokes the membership before the queue has run', function (): void {
    [$workspace, $owner, $leaving, $membership] = memberHolding(3);

    app(RemoveWorkspaceMember::class)->handle($workspace, $owner, $membership);

    expect($workspace->memberships()->where('user_id', $leaving->id)->value('status'))
        ->toBe(WorkspaceMembershipStatus::Revoked)
        ->and(Task::query()->where('assignee_id', $leaving->id)->count())->toBe(3)
        // Filtered by name: creating the tasks also queued indexing jobs.
        ->and(DB::table('jobs')->where('payload', 'like', '%ReleaseRemovedMembersWork%')->count())->toBe(1);
})->with([
    'a removal that waits on a queue is a security answer arriving late; the tasks keeping a
    stale name for a moment is a label, not a grant',
]);

it('unassigns everything they held when the job runs', function (): void {
    [$workspace, $owner, $leaving, $membership] = memberHolding(4);

    app(RemoveWorkspaceMember::class)->handle($workspace, $owner, $membership);

    app(ReleaseRemovedMembersWork::class, [
        'workspaceId' => $workspace->id,
        'removedUserId' => $leaving->id,
        'actorId' => $owner->id,
    ])->handle(app(Dispatcher::class));

    expect(Task::query()->where('workspace_id', $workspace->id)->whereNotNull('assignee_id')->count())->toBe(0);
});

it('leaves their work alone if they were invited back before the job ran', function (): void {
    [$workspace, $owner, $leaving, $membership] = memberHolding(3);

    app(RemoveWorkspaceMember::class)->handle($workspace, $owner, $membership);

    $workspace->memberships()->where('user_id', $leaving->id)->update(['status' => 'active']);

    app(ReleaseRemovedMembersWork::class, [
        'workspaceId' => $workspace->id,
        'removedUserId' => $leaving->id,
        'actorId' => $owner->id,
    ])->handle(app(Dispatcher::class));

    expect(Task::query()->where('assignee_id', $leaving->id)->count())->toBe(3);
})->with([
    'taking the work away would undo a decision somebody made after the removal',
]);

it('leaves somebody elses work alone', function (): void {
    [$workspace, $owner, $leaving, $membership] = memberHolding(2);
    $staying = memberOf($workspace, WorkspaceRole::Member);
    $theirs = Task::factory()->in($workspace)->create(['assignee_id' => $staying->id]);

    app(RemoveWorkspaceMember::class)->handle($workspace, $owner, $membership);

    app(ReleaseRemovedMembersWork::class, [
        'workspaceId' => $workspace->id,
        'removedUserId' => $leaving->id,
        'actorId' => $owner->id,
    ])->handle(app(Dispatcher::class));

    expect($theirs->refresh()->assignee_id)->toBe($staying->id);
});

it('takes them off the tasks they were collaborating on, and nobody else', function (): void {
    [$workspace, $owner, $leaving, $membership] = memberHolding(1);
    $staying = memberOf($workspace, WorkspaceRole::Member);
    $shared = Task::factory()->in($workspace)->create();
    TaskCollaborator::factory()->on($shared, $leaving)->create();
    TaskCollaborator::factory()->on($shared, $staying)->create();

    app(RemoveWorkspaceMember::class)->handle($workspace, $owner, $membership);

    app(ReleaseRemovedMembersWork::class, [
        'workspaceId' => $workspace->id,
        'removedUserId' => $leaving->id,
        'actorId' => $owner->id,
    ])->handle(app(Dispatcher::class));

    expect($shared->collaborators()->pluck('users.id')->all())->toBe([$staying->id])
        ->and(DB::table('activities')->where('type', 'task.collaborator_removed')->count())->toBe(1);
});

it('still releases their work after the person who removed them has lost access', function (): void {
    [$workspace, $owner, $leaving, $membership] = memberHolding(2);
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $shared = Task::factory()->in($workspace)->create();
    TaskCollaborator::factory()->on($shared, $leaving)->create();

    app(RemoveWorkspaceMember::class)->handle($workspace, $admin, $membership);
    app(RemoveWorkspaceMember::class)->handle(
        $workspace,
        $owner,
        $workspace->memberships()->where('user_id', $admin->id)->firstOrFail(),
    );

    app(ReleaseRemovedMembersWork::class, [
        'workspaceId' => $workspace->id,
        'removedUserId' => $leaving->id,
        'actorId' => $admin->id,
    ])->handle(app(Dispatcher::class));

    expect(Task::query()->where('assignee_id', $leaving->id)->count())->toBe(0)
        ->and($shared->collaborations()->count())->toBe(0)
        ->and(DB::table('activities')->whereIn('type', ['task.assigned', 'task.collaborator_removed'])->pluck('actor_id')->unique()->values()->all())
        ->toBe([$admin->id]);
});
