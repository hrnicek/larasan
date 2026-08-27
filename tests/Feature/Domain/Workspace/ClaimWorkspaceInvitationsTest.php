<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Actions\ClaimWorkspaceInvitations;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Carbon\CarbonImmutable;

function unclaimedInvitation(Workspace $workspace, string $email, ?User $invitedBy = null): WorkspaceMembership
{
    return WorkspaceMembership::factory()
        ->invited($invitedBy ?? memberOf($workspace, WorkspaceRole::Admin))
        ->unclaimed($email)
        ->create(['workspace_id' => $workspace->id]);
}

function claimFor(User $user): int
{
    return app(ClaimWorkspaceInvitations::class)->handle($user)->count();
}

it('attaches the account to the invitations waiting for its address', function (): void {
    $workspace = Workspace::factory()->create();
    $invitation = unclaimedInvitation($workspace, 'newcomer@example.com');
    $user = User::factory()->create(['email' => 'newcomer@example.com']);

    expect(claimFor($user))->toBe(1)
        ->and($invitation->fresh()?->user_id)->toBe($user->id);
});

it('claims without joining', function (): void {
    $workspace = Workspace::factory()->create();
    $invitation = unclaimedInvitation($workspace, 'newcomer@example.com');
    $user = User::factory()->create(['email' => 'newcomer@example.com']);

    claimFor($user);

    expect($invitation->fresh()?->status)->toBe(WorkspaceMembershipStatus::Invited)
        ->and($invitation->fresh()?->joined_at)->toBeNull()
        ->and($workspace->membershipFor($user)?->allows(Capability::CommentCreate))->toBeFalse()
        ->and($user->fresh()?->workspaces()->count())->toBe(0);
});

it('compares the address without regard to case', function (): void {
    $workspace = Workspace::factory()->create();
    unclaimedInvitation($workspace, 'newcomer@example.com');
    $user = User::factory()->create(['email' => 'Newcomer@Example.com']);

    expect(claimFor($user))->toBe(1);
});

it('claims an invitation in every workspace that sent one', function (): void {
    $first = Workspace::factory()->create();
    $second = Workspace::factory()->create();
    unclaimedInvitation($first, 'newcomer@example.com');
    unclaimedInvitation($second, 'newcomer@example.com');
    $user = User::factory()->create(['email' => 'newcomer@example.com']);

    expect(claimFor($user))->toBe(2);
});

it('leaves an invitation to another address alone', function (): void {
    $workspace = Workspace::factory()->create();
    $invitation = unclaimedInvitation($workspace, 'somebody-else@example.com');
    $user = User::factory()->create(['email' => 'newcomer@example.com']);

    expect(claimFor($user))->toBe(0)
        ->and($invitation->fresh()?->user_id)->toBeNull();
});

it('drops an invitation to a workspace the account is already in', function (): void {
    /*
     * Reachable by changing an account's address to one that was invited separately. The row
     * they already hold is the authoritative one, and UNIQUE(workspace_id, user_id) refuses a
     * second — so the invitation goes rather than the membership.
     */
    $workspace = Workspace::factory()->create();
    $user = memberOf($workspace, WorkspaceRole::Member);
    $invitation = unclaimedInvitation($workspace, $user->email);

    expect(claimFor($user))->toBe(0)
        ->and(WorkspaceMembership::query()->whereKey($invitation->id)->exists())->toBeFalse()
        ->and($workspace->membershipFor($user)?->status)->toBe(WorkspaceMembershipStatus::Active);
});

it('claims a lapsed invitation too, so it can be resent to the account', function (): void {
    $workspace = Workspace::factory()->create();
    $lapsed = WorkspaceMembership::factory()
        ->invited(memberOf($workspace, WorkspaceRole::Admin), CarbonImmutable::now()->subDay())
        ->unclaimed('newcomer@example.com')
        ->create(['workspace_id' => $workspace->id]);
    $user = User::factory()->create(['email' => 'newcomer@example.com']);

    expect(claimFor($user))->toBe(1)
        ->and($lapsed->fresh()?->user_id)->toBe($user->id);
});

it('claims what is waiting when the account registers', function (): void {
    $workspace = Workspace::factory()->create();
    $invitation = unclaimedInvitation($workspace, 'newcomer@example.com');

    $this->post(route('register.store'), [
        'name' => 'Newcomer',
        'email' => 'newcomer@example.com',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertRedirect();

    expect($invitation->fresh()?->user_id)->toBe(User::query()->where('email', 'newcomer@example.com')->value('id'));
});
