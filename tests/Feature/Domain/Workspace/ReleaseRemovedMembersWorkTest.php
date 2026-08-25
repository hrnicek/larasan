<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Actions\AssignTask;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Actions\RemoveWorkspaceMember;
use App\Domain\Workspace\Jobs\ReleaseRemovedMembersWork;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * Removing somebody used to unassign each of their tasks in the request — four queries per task,
 * and somebody leaving may be holding hundreds (TASK-180-019). The revocation stays in the
 * request, because that is the security answer; the bookkeeping moved to a queue.
 */

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

/*
 * A queue that actually queues, rather than `Queue::fake()`. The fake intercepts `dispatchSync`
 * as well as `dispatch`, so under it the two are indistinguishable and this test could not tell
 * "the work is queued" from "the work is done here" — which is the whole claim.
 */
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
        // Still theirs on paper, and unreachable in practice: the membership is already revoked.
        ->and(Task::query()->where('assignee_id', $leaving->id)->count())->toBe(3)
        // Named rather than counted: creating those three tasks also queued three indexing
        // jobs (ADR-0016), and this test is about the one job the removal itself queues.
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
    ])->handle(app(AssignTask::class));

    expect(Task::query()->where('workspace_id', $workspace->id)->whereNotNull('assignee_id')->count())->toBe(0);
});

it('leaves their work alone if they were invited back before the job ran', function (): void {
    [$workspace, $owner, $leaving, $membership] = memberHolding(3);

    app(RemoveWorkspaceMember::class)->handle($workspace, $owner, $membership);

    // Re-invited and active again before the queue got to it.
    $workspace->memberships()->where('user_id', $leaving->id)->update(['status' => 'active']);

    app(ReleaseRemovedMembersWork::class, [
        'workspaceId' => $workspace->id,
        'removedUserId' => $leaving->id,
        'actorId' => $owner->id,
    ])->handle(app(AssignTask::class));

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
    ])->handle(app(AssignTask::class));

    expect($theirs->refresh()->assignee_id)->toBe($staying->id);
});
