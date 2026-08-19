<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Actions\InviteWorkspaceMember;
use App\Domain\Workspace\Data\InviteWorkspaceMemberData;
use App\Domain\Workspace\Events\WorkspaceMemberInvited;
use App\Domain\Workspace\Exceptions\WorkspaceMembershipException;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Domain\Workspace\Notifications\WorkspaceInvitationSent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

function invite(Workspace $workspace, User $actor, User $invitee, WorkspaceRole $role = WorkspaceRole::Member): WorkspaceMembership
{
    return app(InviteWorkspaceMember::class)->handle(
        $workspace,
        $actor,
        new InviteWorkspaceMemberData(userId: $invitee->id, role: $role),
    );
}

it('creates an invitation that has not joined and has a deadline', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $invitee = User::factory()->create();

    $membership = invite($workspace, $admin, $invitee);

    expect($membership->status)->toBe(WorkspaceMembershipStatus::Invited)
        ->and($membership->role)->toBe(WorkspaceRole::Member)
        ->and($membership->joined_at)->toBeNull()
        ->and($membership->invited_by)->toBe($admin->id)
        ->and($membership->expires_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($membership->allows(Capability::CommentCreate))->toBeFalse();
});

it('queues the invitation notification rather than sending it inline', function (): void {
    Notification::fake();
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $invitee = User::factory()->create();

    invite($workspace, $admin, $invitee);

    /*
     * The class implements ShouldQueue, so asserting that adds nothing PHPStan does not
     * already know. What is worth pinning is the queue: ADR-0008 has Horizon supervising
     * `notifications`, and a notification landing on `default` would make that quietly
     * false.
     */
    Notification::assertSentTo(
        $invitee,
        WorkspaceInvitationSent::class,
        fn (WorkspaceInvitationSent $notification): bool => $notification->queue === 'notifications',
    );
});

it('dispatches the invitation event', function (): void {
    Event::fake();
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $invitee = User::factory()->create();

    $membership = invite($workspace, $admin, $invitee);

    Event::assertDispatched(WorkspaceMemberInvited::class, fn (WorkspaceMemberInvited $event): bool => $event->membershipId === $membership->id
        && $event->workspaceId === $workspace->id
        && $event->userId === $invitee->id
        && $event->invitedById === $admin->id);
});

it('re-invites someone who declined instead of colliding with the unique index', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $invitee = memberOf($workspace, WorkspaceRole::Guest, WorkspaceMembershipStatus::Declined);

    $membership = invite($workspace, $admin, $invitee, WorkspaceRole::Member);

    expect(WorkspaceMembership::query()->where('user_id', $invitee->id)->count())->toBe(1)
        ->and($membership->status)->toBe(WorkspaceMembershipStatus::Invited)
        ->and($membership->role)->toBe(WorkspaceRole::Member);
});

it('refuses to invite someone who is already an active member', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $existing = memberOf($workspace, WorkspaceRole::Member);

    expect(fn (): WorkspaceMembership => invite($workspace, $admin, $existing))
        ->toThrow(WorkspaceMembershipException::class, 'already a member');
});

it('refuses an actor without the members capability, whatever their role', function (WorkspaceRole $role): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);
    $invitee = User::factory()->create();

    expect(fn (): WorkspaceMembership => invite($workspace, $actor, $invitee))
        ->toThrow(WorkspaceMembershipException::class);

    expect(WorkspaceMembership::query()->where('user_id', $invitee->id)->exists())->toBeFalse();
})->with([
    'member' => WorkspaceRole::Member,
    'guest' => WorkspaceRole::Guest,
]);

it('refuses an actor whose own membership is no longer active', function (): void {
    $workspace = Workspace::factory()->create();
    $revoked = memberOf($workspace, WorkspaceRole::Owner, WorkspaceMembershipStatus::Revoked);
    $invitee = User::factory()->create();

    expect(fn (): WorkspaceMembership => invite($workspace, $revoked, $invitee))
        ->toThrow(WorkspaceMembershipException::class);
});

it('refuses to invite anyone as owner', function (): void {
    $workspace = Workspace::factory()->create();
    $owner = memberOf($workspace, WorkspaceRole::Owner);
    $invitee = User::factory()->create();

    expect(fn (): WorkspaceMembership => invite($workspace, $owner, $invitee, WorkspaceRole::Owner))
        ->toThrow(WorkspaceMembershipException::class, 'one owner');
});

it('does not let an invitation from one workspace grant anything in another', function (): void {
    $first = Workspace::factory()->create();
    $second = Workspace::factory()->create();
    $admin = memberOf($first, WorkspaceRole::Admin);
    $invitee = User::factory()->create();

    invite($first, $admin, $invitee);

    expect($second->membershipFor($invitee))->toBeNull()
        ->and($invitee->fresh()?->workspaces()->count())->toBe(0);
});

it('refuses to rewrite a revoked owner through an invitation', function (): void {
    /*
     * updateOrCreate overwrites the row, so inviting a revoked owner as a member would
     * demote them permanently — Owner is never granted again — and strand the owner_id
     * holder, whose account cannot be deleted while they own a workspace.
     */
    $workspace = Workspace::factory()->create();
    memberOf($workspace, WorkspaceRole::Owner);
    $formerOwner = memberOf($workspace, WorkspaceRole::Owner, WorkspaceMembershipStatus::Revoked);
    $admin = memberOf($workspace, WorkspaceRole::Admin);

    expect(fn (): WorkspaceMembership => invite($workspace, $admin, $formerOwner))
        ->toThrow(WorkspaceMembershipException::class, 'Only an owner');

    expect($workspace->membershipFor($formerOwner)?->role)->toBe(WorkspaceRole::Owner);
});

it('lets an owner re-invite a revoked owner', function (): void {
    $workspace = Workspace::factory()->create();
    $owner = memberOf($workspace, WorkspaceRole::Owner);
    $formerOwner = memberOf($workspace, WorkspaceRole::Owner, WorkspaceMembershipStatus::Revoked);

    $membership = invite($workspace, $owner, $formerOwner, WorkspaceRole::Admin);

    expect($membership->status)->toBe(WorkspaceMembershipStatus::Invited)
        ->and($membership->role)->toBe(WorkspaceRole::Admin);
});

it('does not re-send an invitation that is still waiting', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $invitee = User::factory()->create();

    invite($workspace, $admin, $invitee);

    expect(fn (): WorkspaceMembership => invite($workspace, $admin, $invitee))
        ->toThrow(WorkspaceMembershipException::class, 'already has an invitation');
});

it('re-invites once the previous invitation has lapsed', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $invitee = User::factory()->create();

    $membership = invite($workspace, $admin, $invitee);
    $membership->forceFill(['expires_at' => CarbonImmutable::now()->subDay()])->save();

    expect(invite($workspace, $admin, $invitee)->status)->toBe(WorkspaceMembershipStatus::Invited);
});
