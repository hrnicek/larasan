<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Actions\ChangeWorkspaceMemberRole;
use App\Domain\Workspace\Actions\RemoveWorkspaceMember;
use App\Domain\Workspace\Events\WorkspaceMemberRemoved;
use App\Domain\Workspace\Events\WorkspaceMemberRoleChanged;
use App\Domain\Workspace\Exceptions\WorkspaceMembershipException;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Support\Facades\Event;

function remove(Workspace $workspace, User $actor, WorkspaceMembership $membership): WorkspaceMembership
{
    return app(RemoveWorkspaceMember::class)->handle($workspace, $actor, $membership);
}

function changeRole(Workspace $workspace, User $actor, WorkspaceMembership $membership, WorkspaceRole $role): WorkspaceMembership
{
    return app(ChangeWorkspaceMemberRole::class)->handle($workspace, $actor, $membership, $role);
}

it('revokes the membership instead of deleting the record', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $member = memberOf($workspace, WorkspaceRole::Member);
    $membership = $workspace->membershipFor($member);

    remove($workspace, $admin, $membership);

    expect($membership->fresh()?->status)->toBe(WorkspaceMembershipStatus::Revoked)
        ->and($membership->fresh()?->joined_at)->not->toBeNull()
        ->and($membership->fresh()?->allows(Capability::CommentCreate))->toBeFalse()
        ->and($member->fresh()?->workspaces()->count())->toBe(0);
});

it('keeps the last owner', function (): void {
    $workspace = Workspace::factory()->create();
    $owner = memberOf($workspace, WorkspaceRole::Owner);
    $membership = $workspace->membershipFor($owner);

    expect(fn (): WorkspaceMembership => remove($workspace, $owner, $membership))
        ->toThrow(WorkspaceMembershipException::class, 'at least one owner');

    expect($membership->fresh()?->status)->toBe(WorkspaceMembershipStatus::Active);
});

it('lets an owner go once another owner exists', function (): void {
    $workspace = Workspace::factory()->create();
    $first = memberOf($workspace, WorkspaceRole::Owner);
    $second = memberOf($workspace, WorkspaceRole::Owner);

    remove($workspace, $second, $workspace->membershipFor($first));

    expect($workspace->membershipFor($first)?->status)->toBe(WorkspaceMembershipStatus::Revoked);
});

it('does not count a revoked owner towards the last-owner rule', function (): void {
    $workspace = Workspace::factory()->create();
    $active = memberOf($workspace, WorkspaceRole::Owner);
    memberOf($workspace, WorkspaceRole::Owner, WorkspaceMembershipStatus::Revoked);

    expect(fn (): WorkspaceMembership => remove($workspace, $active, $workspace->membershipFor($active)))
        ->toThrow(WorkspaceMembershipException::class, 'at least one owner');
});

it('refuses removal by an actor without the capability', function (WorkspaceRole $role): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);
    $target = memberOf($workspace, WorkspaceRole::Member);

    expect(fn (): WorkspaceMembership => remove($workspace, $actor, $workspace->membershipFor($target)))
        ->toThrow(WorkspaceMembershipException::class);

    expect($workspace->membershipFor($target)?->status)->toBe(WorkspaceMembershipStatus::Active);
})->with(['member' => WorkspaceRole::Member, 'guest' => WorkspaceRole::Guest]);

it('announces the removal', function (): void {
    Event::fake();
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $member = memberOf($workspace, WorkspaceRole::Member);

    remove($workspace, $admin, $workspace->membershipFor($member));

    Event::assertDispatched(WorkspaceMemberRemoved::class, fn (WorkspaceMemberRemoved $event): bool => $event->userId === $member->id && $event->removedById === $admin->id);
});

it('changes a role and announces what it was', function (): void {
    Event::fake();
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $guest = memberOf($workspace, WorkspaceRole::Guest);

    $membership = changeRole($workspace, $admin, $workspace->membershipFor($guest), WorkspaceRole::Member);

    expect($membership->fresh()?->role)->toBe(WorkspaceRole::Member)
        ->and($membership->fresh()?->allows(Capability::TaskCreate))->toBeTrue();

    Event::assertDispatched(WorkspaceMemberRoleChanged::class, fn (WorkspaceMemberRoleChanged $event): bool => $event->from === WorkspaceRole::Guest && $event->to === WorkspaceRole::Member);
});

it('refuses to change your own role, even as owner', function (): void {
    $workspace = Workspace::factory()->create();
    $owner = memberOf($workspace, WorkspaceRole::Owner);
    memberOf($workspace, WorkspaceRole::Owner);

    expect(fn (): WorkspaceMembership => changeRole($workspace, $owner, $workspace->membershipFor($owner), WorkspaceRole::Member))
        ->toThrow(WorkspaceMembershipException::class, 'own role');

    expect($workspace->membershipFor($owner)?->role)->toBe(WorkspaceRole::Owner);
});

it('refuses to assign the owner role', function (): void {
    $workspace = Workspace::factory()->create();
    $owner = memberOf($workspace, WorkspaceRole::Owner);
    $member = memberOf($workspace, WorkspaceRole::Member);

    expect(fn (): WorkspaceMembership => changeRole($workspace, $owner, $workspace->membershipFor($member), WorkspaceRole::Owner))
        ->toThrow(WorkspaceMembershipException::class, 'transferred, not assigned');

    expect($workspace->membershipFor($member)?->role)->toBe(WorkspaceRole::Member);
});

it('refuses to demote the last owner', function (): void {
    $workspace = Workspace::factory()->create();
    $owner = memberOf($workspace, WorkspaceRole::Owner);
    $admin = memberOf($workspace, WorkspaceRole::Admin);

    expect(fn (): WorkspaceMembership => changeRole($workspace, $admin, $workspace->membershipFor($owner), WorkspaceRole::Member))
        ->toThrow(WorkspaceMembershipException::class, 'at least one owner');

    expect($workspace->membershipFor($owner)?->role)->toBe(WorkspaceRole::Owner);
});

it('refuses a role change by an actor without the capability', function (): void {
    $workspace = Workspace::factory()->create();
    $member = memberOf($workspace, WorkspaceRole::Member);
    $guest = memberOf($workspace, WorkspaceRole::Guest);

    expect(fn (): WorkspaceMembership => changeRole($workspace, $member, $workspace->membershipFor($guest), WorkspaceRole::Member))
        ->toThrow(WorkspaceMembershipException::class);
});

it('does not reach across workspaces', function (): void {
    $theirs = Workspace::factory()->create();
    $mine = Workspace::factory()->create();
    $outsiderAdmin = memberOf($mine, WorkspaceRole::Admin);
    $target = memberOf($theirs, WorkspaceRole::Member);

    expect(fn (): WorkspaceMembership => remove($theirs, $outsiderAdmin, $theirs->membershipFor($target)))
        ->toThrow(WorkspaceMembershipException::class);

    expect($theirs->membershipFor($target)?->status)->toBe(WorkspaceMembershipStatus::Active);
});
