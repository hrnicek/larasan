<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;

function signedInvitationLink(WorkspaceMembership $invitation): string
{
    return URL::temporarySignedRoute(
        'workspaces.invitations.show',
        $invitation->expires_at ?? CarbonImmutable::now()->addWeek(),
        ['membership' => $invitation->id],
    );
}

function invitationFor(User $invitee, ?Workspace $workspace = null): WorkspaceMembership
{
    $workspace = $workspace ?? Workspace::factory()->create();

    return WorkspaceMembership::factory()
        ->invited(memberOf($workspace, WorkspaceRole::Admin))
        ->create(['workspace_id' => $workspace->id, 'user_id' => $invitee->id]);
}

it('refuses an invitation link that is not signed', function (): void {
    $invitation = invitationFor(User::factory()->create());

    $this->get(route('workspaces.invitations.show', ['membership' => $invitation->id]))
        ->assertForbidden();
});

it('sends a visitor with no account to register, with the address filled in', function (): void {
    $workspace = Workspace::factory()->create();
    $invitation = WorkspaceMembership::factory()
        ->invited(memberOf($workspace, WorkspaceRole::Admin))
        ->unclaimed('newcomer@example.com')
        ->create(['workspace_id' => $workspace->id]);

    $link = signedInvitationLink($invitation);

    $this->get($link)->assertRedirect(route('register'));

    expect(session('invitation_email'))->toBe('newcomer@example.com')
        ->and(session('url.intended'))->toBe($link);
});

it('offers the register screen the address it was invited at', function (): void {
    $this->withSession(['invitation_email' => 'newcomer@example.com'])
        ->get(route('register'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('auth/Register')
            ->where('email', 'newcomer@example.com'));
});

it('sends a visitor whose address has an account to log in', function (): void {
    $invitee = User::factory()->create(['email' => 'known@example.com']);
    $invitation = invitationFor($invitee);

    $link = signedInvitationLink($invitation);

    $this->get($link)->assertRedirect(route('login'));

    expect(session('status'))->toContain('known@example.com')
        ->and(session('url.intended'))->toBe($link);
});

it('claims the invitation when the invitee follows the link signed in', function (): void {
    $workspace = Workspace::factory()->create();
    $invitation = WorkspaceMembership::factory()
        ->invited(memberOf($workspace, WorkspaceRole::Admin))
        ->unclaimed('newcomer@example.com')
        ->create(['workspace_id' => $workspace->id]);
    $invitee = User::factory()->create(['email' => 'newcomer@example.com']);

    $this->actingAs($invitee)
        ->get(signedInvitationLink($invitation))
        ->assertRedirect(route('workspaces.index'));

    expect($invitation->fresh()?->user_id)->toBe($invitee->id);
});

it('tells somebody signed in as another account that the invitation is not theirs', function (): void {
    $workspace = Workspace::factory()->create();
    $invitation = WorkspaceMembership::factory()
        ->invited(memberOf($workspace, WorkspaceRole::Admin))
        ->unclaimed('newcomer@example.com')
        ->create(['workspace_id' => $workspace->id]);

    $this->actingAs(User::factory()->create(['email' => 'somebody@example.com']))
        ->get(signedInvitationLink($invitation))
        ->assertRedirect(route('workspaces.index'));

    /** @var array<string, array<string, string>> $flashed */
    $flashed = session()->get(SessionKey::FLASH_DATA, []);

    expect($flashed['toast']['type'])->toBe('error')
        ->and($invitation->fresh()?->user_id)->toBeNull();
});

it('lists the invitations waiting for the actor', function (): void {
    $invitee = User::factory()->create();
    invitationFor($invitee);

    $this->actingAs($invitee)
        ->get(route('workspaces.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('workspaces/Index')
            ->has('workspaces', 0)
            ->has('invitations', 1)
            ->where('invitations.0.role', 'member')
            ->where('invitations.0.hasExpired', false));
});

it('accepts an invitation and lands in the workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $invitee = User::factory()->create();
    $invitation = invitationFor($invitee, $workspace);

    $this->actingAs($invitee)
        ->post(route('workspaces.invitations.accept', ['membership' => $invitation->id]))
        ->assertRedirect(route('dashboard'));

    expect($invitation->fresh()?->status)->toBe(WorkspaceMembershipStatus::Active)
        ->and($invitee->fresh()?->current_workspace_id)->toBe($workspace->id);
});

it('declines an invitation without joining anything', function (): void {
    $invitee = User::factory()->create();
    $invitation = invitationFor($invitee);

    $this->actingAs($invitee)
        ->post(route('workspaces.invitations.decline', ['membership' => $invitation->id]))
        ->assertRedirect(route('workspaces.index'));

    expect($invitation->fresh()?->status)->toBe(WorkspaceMembershipStatus::Declined)
        ->and($invitee->fresh()?->workspaces()->count())->toBe(0);
});

it('does not let anybody answer an invitation that is not theirs', function (): void {
    $invitation = invitationFor(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->post(route('workspaces.invitations.accept', ['membership' => $invitation->id]))
        ->assertNotFound();

    expect($invitation->fresh()?->status)->toBe(WorkspaceMembershipStatus::Invited);
});

it('shows the refusal when the invitation has lapsed', function (): void {
    $workspace = Workspace::factory()->create();
    $invitee = User::factory()->create();
    $invitation = WorkspaceMembership::factory()
        ->invited(memberOf($workspace, WorkspaceRole::Admin), CarbonImmutable::now()->subDay())
        ->create(['workspace_id' => $workspace->id, 'user_id' => $invitee->id]);

    $this->actingAs($invitee)
        ->from(route('workspaces.index'))
        ->post(route('workspaces.invitations.accept', ['membership' => $invitation->id]))
        ->assertRedirect(route('workspaces.index'))
        ->assertSessionHasErrors('refusal');

    expect($invitation->fresh()?->status)->toBe(WorkspaceMembershipStatus::Invited);
});

it('does not claim for a signed-in account that has not verified the address', function (): void {
    $workspace = Workspace::factory()->create();
    $invitation = WorkspaceMembership::factory()
        ->invited(memberOf($workspace, WorkspaceRole::Admin))
        ->unclaimed('newcomer@example.com')
        ->create(['workspace_id' => $workspace->id]);
    $squatter = User::factory()->unverified()->create(['email' => 'newcomer@example.com']);

    $this->actingAs($squatter)->get(signedInvitationLink($invitation));

    expect($invitation->fresh()?->user_id)->toBeNull();
});

it('sends a visitor whose account stores the address in another case to log in', function (): void {
    $workspace = Workspace::factory()->create();
    $invitation = WorkspaceMembership::factory()
        ->invited(memberOf($workspace, WorkspaceRole::Admin))
        ->unclaimed('known@example.com')
        ->create(['workspace_id' => $workspace->id]);
    User::factory()->create()->forceFill(['email' => 'Known@Example.com'])->save();

    $this->get(signedInvitationLink($invitation))->assertRedirect(route('login'));
});

it('answers a malformed invitation id with not found', function (): void {
    $this->get(URL::temporarySignedRoute('workspaces.invitations.show', CarbonImmutable::now()->addHour(), ['membership' => 'not-a-uuid']))
        ->assertNotFound();

    $this->actingAs(User::factory()->create())
        ->post(route('workspaces.invitations.accept', ['membership' => 'not-a-uuid']))
        ->assertNotFound();

    $this->post(route('workspaces.invitations.decline', ['membership' => 'not-a-uuid']))
        ->assertNotFound();
});
