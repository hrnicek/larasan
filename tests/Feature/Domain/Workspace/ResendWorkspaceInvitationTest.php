<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Actions\ResendWorkspaceInvitation;
use App\Domain\Workspace\Exceptions\WorkspaceMembershipException;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Domain\Workspace\Notifications\WorkspaceInvitationSent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Support\SessionKey;

function resend(Workspace $workspace, User $actor, WorkspaceMembership $membership): WorkspaceMembership
{
    return app(ResendWorkspaceInvitation::class)->handle($workspace, $actor, $membership);
}

it('gives a lapsed invitation a fresh deadline and sends it again', function (): void {
    Notification::fake();
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $invitee = User::factory()->create();
    $lapsed = WorkspaceMembership::factory()
        ->invited($admin, CarbonImmutable::now()->subDay())
        ->create(['workspace_id' => $workspace->id, 'user_id' => $invitee->id]);

    $resent = resend($workspace, $admin, $lapsed);

    expect($resent->status)->toBe(WorkspaceMembershipStatus::Invited)
        ->and($resent->hasExpired())->toBeFalse();

    Notification::assertSentTo($invitee, WorkspaceInvitationSent::class);
});

it('sends an unclaimed invitation to the address again', function (): void {
    Notification::fake();
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $invitation = WorkspaceMembership::factory()
        ->invited($admin)
        ->unclaimed('nobody@example.com')
        ->create(['workspace_id' => $workspace->id]);

    resend($workspace, $admin, $invitation);

    Notification::assertSentOnDemand(
        WorkspaceInvitationSent::class,
        fn (WorkspaceInvitationSent $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'nobody@example.com',
    );
});

it('makes the resender the inviter, so acceptance asks about somebody who is still here', function (): void {
    /*
     * Acceptance refuses an invitation whose `invited_by` no longer holds the capability. An
     * invitation from somebody since removed is dead until a manager sends it again — which is
     * exactly that manager saying it stands.
     */
    $workspace = Workspace::factory()->create();
    $gone = memberOf($workspace, WorkspaceRole::Admin, WorkspaceMembershipStatus::Revoked);
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $invitation = WorkspaceMembership::factory()
        ->invited($gone)
        ->create(['workspace_id' => $workspace->id, 'user_id' => User::factory()->create()->id]);

    expect(resend($workspace, $admin, $invitation)->invited_by)->toBe($admin->id);
});

it('refuses to resend to somebody who is already a member', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $member = memberOf($workspace, WorkspaceRole::Member);

    expect(fn (): WorkspaceMembership => resend($workspace, $admin, $workspace->membershipFor($member)))
        ->toThrow(WorkspaceMembershipException::class, 'already a member');
});

it('refuses to resend an invitation that was declined', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $declined = memberOf($workspace, WorkspaceRole::Member, WorkspaceMembershipStatus::Declined);

    expect(fn (): WorkspaceMembership => resend($workspace, $admin, $workspace->membershipFor($declined)))
        ->toThrow(WorkspaceMembershipException::class, 'declined');
});

it('refuses an actor without the members capability', function (WorkspaceRole $role): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);
    $invitation = WorkspaceMembership::factory()
        ->invited(memberOf($workspace, WorkspaceRole::Admin))
        ->unclaimed('nobody@example.com')
        ->create(['workspace_id' => $workspace->id]);

    expect(fn (): WorkspaceMembership => resend($workspace, $actor, $invitation))
        ->toThrow(WorkspaceMembershipException::class);
})->with([
    'member' => WorkspaceRole::Member,
    'guest' => WorkspaceRole::Guest,
]);

it('resends from the members screen', function (): void {
    Notification::fake();
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $invitation = WorkspaceMembership::factory()
        ->invited($admin, CarbonImmutable::now()->subDay())
        ->unclaimed('nobody@example.com')
        ->create(['workspace_id' => $workspace->id]);

    $this->actingAs($admin)
        ->post(route('workspaces.members.resend', ['membership' => $invitation->id]))
        ->assertRedirect(route('workspaces.members'));

    expect($invitation->fresh()?->hasExpired())->toBeFalse();
});

it('does not resend an invitation belonging to another workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $elsewhere = Workspace::factory()->create();
    $invitation = WorkspaceMembership::factory()
        ->invited(memberOf($elsewhere, WorkspaceRole::Admin))
        ->unclaimed('nobody@example.com')
        ->create(['workspace_id' => $elsewhere->id]);

    $this->actingAs($admin)
        ->post(route('workspaces.members.resend', ['membership' => $invitation->id]))
        ->assertNotFound();
});

it('cancels an unclaimed invitation from the members screen', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $invitation = WorkspaceMembership::factory()
        ->invited($admin)
        ->unclaimed('nobody@example.com')
        ->create(['workspace_id' => $workspace->id]);

    $this->actingAs($admin)
        ->delete(route('workspaces.members.destroy', ['membership' => $invitation->id]))
        ->assertRedirect(route('workspaces.members'));

    expect(WorkspaceMembership::query()->whereKey($invitation->id)->exists())->toBeFalse();

    /** @var array<string, array<string, string>> $flashed */
    $flashed = session()->get(SessionKey::FLASH_DATA, []);

    // Taking an invitation back and removing somebody who is here are the same endpoint and
    // not the same sentence.
    expect($flashed['toast']['message'])->toBe('Invitation cancelled.');
});
