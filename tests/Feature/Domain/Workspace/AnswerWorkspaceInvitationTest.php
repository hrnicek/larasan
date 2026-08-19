<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Actions\AnswerWorkspaceInvitation;
use App\Domain\Workspace\Events\WorkspaceInvitationAnswered;
use App\Domain\Workspace\Exceptions\WorkspaceMembershipException;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

/**
 * @return array{WorkspaceMembership, User}
 */
function pendingInvitation(?CarbonImmutable $expiresAt = null, WorkspaceRole $role = WorkspaceRole::Member): array
{
    $workspace = Workspace::factory()->create();
    $invitee = User::factory()->create();

    // Always with an inviter who still may invite: that is what the Action produces, and
    // an invitation with nobody behind it is refused on purpose.
    $inviter = memberOf($workspace, WorkspaceRole::Admin);

    $membership = WorkspaceMembership::factory()->invited($inviter, $expiresAt)->create([
        'workspace_id' => $workspace->id,
        'user_id' => $invitee->id,
        'role' => $role,
    ]);

    return [$membership, $invitee];
}

it('accepting joins the workspace and clears the deadline', function (): void {
    [$membership, $invitee] = pendingInvitation();

    app(AnswerWorkspaceInvitation::class)->accept($membership, $invitee);

    $membership->refresh();

    expect($membership->status)->toBe(WorkspaceMembershipStatus::Active)
        ->and($membership->joined_at)->not->toBeNull()
        ->and($membership->expires_at)->toBeNull()
        ->and($membership->allows(Capability::TaskCreate))->toBeTrue()
        ->and($invitee->fresh()?->workspaces()->count())->toBe(1);
});

it('declining leaves the workspace unjoined', function (): void {
    [$membership, $invitee] = pendingInvitation();

    app(AnswerWorkspaceInvitation::class)->decline($membership, $invitee);

    $membership->refresh();

    expect($membership->status)->toBe(WorkspaceMembershipStatus::Declined)
        ->and($membership->joined_at)->toBeNull()
        ->and($membership->expires_at)->toBeNull()
        ->and($membership->allows(Capability::CommentCreate))->toBeFalse()
        ->and($invitee->fresh()?->workspaces()->count())->toBe(0);
});

it('announces which answer it was', function (string $method, WorkspaceMembershipStatus $answer): void {
    Event::fake();
    [$membership, $invitee] = pendingInvitation();

    app(AnswerWorkspaceInvitation::class)->{$method}($membership, $invitee);

    Event::assertDispatched(WorkspaceInvitationAnswered::class, fn (WorkspaceInvitationAnswered $event): bool => $event->membershipId === $membership->id && $event->answer === $answer);
})->with([
    'accept' => ['accept', WorkspaceMembershipStatus::Active],
    'decline' => ['decline', WorkspaceMembershipStatus::Declined],
]);

it('refuses an answer from anyone but the invitee', function (string $method): void {
    [$membership] = pendingInvitation();
    $stranger = User::factory()->create();

    expect(fn (): WorkspaceMembership => app(AnswerWorkspaceInvitation::class)->{$method}($membership, $stranger))
        ->toThrow(WorkspaceMembershipException::class, 'only be answered by');

    expect($membership->fresh()?->status)->toBe(WorkspaceMembershipStatus::Invited);
})->with(['accept', 'decline']);

it('refuses to answer a membership that is not a pending invitation', function (WorkspaceMembershipStatus $status): void {
    $workspace = Workspace::factory()->create();
    $user = memberOf($workspace, WorkspaceRole::Member, $status);
    $membership = $workspace->membershipFor($user);

    expect(fn (): WorkspaceMembership => app(AnswerWorkspaceInvitation::class)->accept($membership, $user))
        ->toThrow(WorkspaceMembershipException::class, 'can no longer be answered');

    expect($membership->fresh()?->status)->toBe($status);
})->with([
    'active' => WorkspaceMembershipStatus::Active,
    'declined' => WorkspaceMembershipStatus::Declined,
    'revoked' => WorkspaceMembershipStatus::Revoked,
    'expired' => WorkspaceMembershipStatus::Expired,
]);

it('refuses to accept after the deadline, before any sweep has run', function (): void {
    [$membership, $invitee] = pendingInvitation(CarbonImmutable::now()->subMinute());

    expect(fn (): WorkspaceMembership => app(AnswerWorkspaceInvitation::class)->accept($membership, $invitee))
        ->toThrow(WorkspaceMembershipException::class, 'expired');

    expect($membership->fresh()?->status)->toBe(WorkspaceMembershipStatus::Invited)
        ->and($invitee->fresh()?->workspaces()->count())->toBe(0);
});

it('lets a lapsed invitation be declined', function (): void {
    [$membership, $invitee] = pendingInvitation(CarbonImmutable::now()->subMinute());

    app(AnswerWorkspaceInvitation::class)->decline($membership, $invitee);

    expect($membership->fresh()?->status)->toBe(WorkspaceMembershipStatus::Declined);
});

it('refuses an invitation whose sender can no longer invite', function (): void {
    /*
     * An admin on their way out could otherwise invite an account they control, lose
     * their membership, and have it accepted afterwards — a back door that survives
     * their removal.
     */
    $workspace = Workspace::factory()->create();
    $inviter = memberOf($workspace, WorkspaceRole::Admin);
    $invitee = User::factory()->create();

    $membership = WorkspaceMembership::factory()->invited($inviter)->create([
        'workspace_id' => $workspace->id,
        'user_id' => $invitee->id,
    ]);

    $workspace->membershipFor($inviter)?->forceFill(['status' => WorkspaceMembershipStatus::Revoked])->save();

    expect(fn (): WorkspaceMembership => app(AnswerWorkspaceInvitation::class)->accept($membership, $invitee))
        ->toThrow(WorkspaceMembershipException::class, 'can no longer invite');

    expect($membership->fresh()?->status)->toBe(WorkspaceMembershipStatus::Invited)
        ->and($invitee->fresh()?->workspaces()->count())->toBe(0);
});

it('still lets it be declined once the sender has gone', function (): void {
    $workspace = Workspace::factory()->create();
    $inviter = memberOf($workspace, WorkspaceRole::Admin);
    $invitee = User::factory()->create();

    $membership = WorkspaceMembership::factory()->invited($inviter)->create([
        'workspace_id' => $workspace->id,
        'user_id' => $invitee->id,
    ]);

    $workspace->membershipFor($inviter)?->forceFill(['status' => WorkspaceMembershipStatus::Revoked])->save();

    app(AnswerWorkspaceInvitation::class)->decline($membership, $invitee);

    expect($membership->fresh()?->status)->toBe(WorkspaceMembershipStatus::Declined);
});

it('refuses an invitation with nobody behind it', function (): void {
    /*
     * `invited_by` is null-on-delete and a non-owner admin may delete their own account,
     * which would otherwise revive the invitation this guard exists to kill. A
     * legitimately null inviter belongs to a workspace creator, and those rows are Active.
     */
    $workspace = Workspace::factory()->create();
    $invitee = User::factory()->create();

    $membership = WorkspaceMembership::factory()->invited()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $invitee->id,
    ]);

    expect(fn (): WorkspaceMembership => app(AnswerWorkspaceInvitation::class)->accept($membership, $invitee))
        ->toThrow(WorkspaceMembershipException::class, 'can no longer invite');
});

it('refuses an invitation from someone since demoted, not only someone removed', function (): void {
    $workspace = Workspace::factory()->create();
    $inviter = memberOf($workspace, WorkspaceRole::Admin);
    $invitee = User::factory()->create();

    $membership = WorkspaceMembership::factory()->invited($inviter)->create([
        'workspace_id' => $workspace->id,
        'user_id' => $invitee->id,
    ]);

    $workspace->membershipFor($inviter)?->forceFill(['role' => WorkspaceRole::Member])->save();

    expect(fn (): WorkspaceMembership => app(AnswerWorkspaceInvitation::class)->accept($membership, $invitee))
        ->toThrow(WorkspaceMembershipException::class, 'can no longer invite');
});
