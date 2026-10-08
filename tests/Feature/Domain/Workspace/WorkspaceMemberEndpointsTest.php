<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

it('lists the workspace members with what the actor may do', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    memberOf($workspace, WorkspaceRole::Owner);

    $this->actingAs($admin)
        ->get(route('workspaces.members'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('settings/Members')
            ->has('members', 2)
            ->has('invitations', 0)
            ->where('can.manageMembers', true)
            ->where('roles', ['member', 'admin', 'guest']));
});

it('tells a member they may look but not manage', function (): void {
    $workspace = Workspace::factory()->create();
    $member = memberOf($workspace, WorkspaceRole::Member);

    $this->actingAs($member)
        ->get(route('workspaces.members'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('can.manageMembers', false));
});

it('marks the last owner so the UI cannot offer to remove them', function (): void {
    $workspace = Workspace::factory()->create();
    $owner = memberOf($workspace, WorkspaceRole::Owner);
    memberOf($workspace, WorkspaceRole::Admin);

    $this->actingAs($owner)
        ->get(route('workspaces.members'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('members.0.isLastOwner', true)
            ->where('members.0.isYou', true));
});

it('invites an existing account', function (): void {
    Notification::fake();
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $invitee = User::factory()->create(['email' => 'new@example.com']);

    $this->actingAs($admin)
        ->post(route('workspaces.members.store'), ['email' => 'new@example.com', 'role' => 'member'])
        ->assertRedirect(route('workspaces.members'));

    expect($workspace->membershipFor($invitee)?->status)->toBe(WorkspaceMembershipStatus::Invited);
});

it('invites an address with no account', function (): void {
    Notification::fake();
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);

    $this->actingAs($admin)
        ->post(route('workspaces.members.store'), ['email' => 'nobody@example.com', 'role' => 'member'])
        ->assertRedirect(route('workspaces.members'));

    $invitation = $workspace->memberships()->whereNull('user_id')->sole();

    expect($invitation->email)->toBe('nobody@example.com')
        ->and($invitation->status)->toBe(WorkspaceMembershipStatus::Invited);
});

it('turns a domain refusal into a validation error the form can show', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $existing = memberOf($workspace, WorkspaceRole::Member);

    $this->actingAs($admin)
        ->post(route('workspaces.members.store'), ['email' => $existing->email, 'role' => 'member'])
        ->assertInvalid(['email' => 'already a member']);
});

it('refuses to invite as owner at the form as well as in the action', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $invitee = User::factory()->create(['email' => 'new@example.com']);

    $this->actingAs($admin)
        ->post(route('workspaces.members.store'), ['email' => 'new@example.com', 'role' => 'owner'])
        ->assertInvalid('role');

    expect($workspace->membershipFor($invitee))->toBeNull();
});

it('changes a role', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $membership = $workspace->membershipFor($guest);

    $this->actingAs($admin)
        ->put(route('workspaces.members.update', $membership->id), ['role' => 'member'])
        ->assertRedirect(route('workspaces.members'));

    expect($membership->fresh()?->role)->toBe(WorkspaceRole::Member);
});

it('removes a member', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $member = memberOf($workspace, WorkspaceRole::Member);
    $membership = $workspace->membershipFor($member);

    $this->actingAs($admin)
        ->delete(route('workspaces.members.destroy', $membership->id))
        ->assertRedirect(route('workspaces.members'));

    expect($membership->fresh()?->status)->toBe(WorkspaceMembershipStatus::Revoked);
});

it('refuses management to a member whatever the UI rendered', function (string $method, string $route): void {
    $workspace = Workspace::factory()->create();
    $member = memberOf($workspace, WorkspaceRole::Member);
    $target = memberOf($workspace, WorkspaceRole::Guest);
    $membership = $workspace->membershipFor($target);

    $url = $route === 'workspaces.members.store'
        ? route($route)
        : route($route, $membership->id);

    $this->actingAs($member)
        ->{$method}($url, ['email' => 'new@example.com', 'role' => 'member'])
        ->assertForbidden();

    expect($membership->fresh()?->role)->toBe(WorkspaceRole::Guest)
        ->and($membership->fresh()?->status)->toBe(WorkspaceMembershipStatus::Active);
})->with([
    'invite' => ['post', 'workspaces.members.store'],
    'change role' => ['put', 'workspaces.members.update'],
    'remove' => ['delete', 'workspaces.members.destroy'],
]);

it('hides another tenant behind a 404 rather than a permission error', function (string $method): void {
    $mine = Workspace::factory()->create();
    $theirs = Workspace::factory()->create();
    $admin = memberOf($mine, WorkspaceRole::Admin);
    $stranger = memberOf($theirs, WorkspaceRole::Member);
    $membership = $theirs->membershipFor($stranger);

    $route = $method === 'put' ? 'workspaces.members.update' : 'workspaces.members.destroy';

    $this->actingAs($admin)
        ->{$method}(route($route, $membership->id), ['role' => 'guest'])
        ->assertNotFound();

    expect($membership->fresh()?->role)->toBe(WorkspaceRole::Member)
        ->and($membership->fresh()?->status)->toBe(WorkspaceMembershipStatus::Active);
})->with(['put', 'delete']);

it('refuses to remove the last owner through the endpoint', function (): void {
    $workspace = Workspace::factory()->create();
    $owner = memberOf($workspace, WorkspaceRole::Owner);
    $membership = $workspace->membershipFor($owner);

    $this->actingAs($owner)
        ->delete(route('workspaces.members.destroy', $membership->id))
        ->assertInvalid(['membership' => 'at least one owner']);

    expect($membership->fresh()?->status)->toBe(WorkspaceMembershipStatus::Active);
});

it('refuses an invitation from someone who is in no workspace', function (): void {
    // 403 rather than 404: the request names no workspace, so there is no resource to conceal.
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->post(route('workspaces.members.store'), ['email' => 'new@example.com', 'role' => 'member'])
        ->assertForbidden();
});

it('shows addresses to a manager and withholds them from everyone else', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $member = memberOf($workspace, WorkspaceRole::Member);

    $this->actingAs($admin)
        ->get(route('workspaces.members'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('members.0.email', $admin->email));

    $this->actingAs($member)
        ->get(route('workspaces.members'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('members.0.email', null)
            ->where('members.1.email', $member->email));
});

it('throttles invitations so the endpoint is not a mail cannon', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    User::factory()->create(['email' => 'target@example.com']);

    foreach (range(1, 10) as $attempt) {
        $this->actingAs($admin)
            ->post(route('workspaces.members.store'), ['email' => 'target@example.com', 'role' => 'member']);
    }

    $this->actingAs($admin)
        ->post(route('workspaces.members.store'), ['email' => 'target@example.com', 'role' => 'member'])
        ->assertStatus(429);
});

it('refuses an admin acting on an owner through the endpoints', function (string $method): void {
    $workspace = Workspace::factory()->create();
    memberOf($workspace, WorkspaceRole::Owner);
    $target = memberOf($workspace, WorkspaceRole::Owner);
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $membership = $workspace->membershipFor($target);

    $response = $method === 'delete'
        ? $this->actingAs($admin)->delete(route('workspaces.members.destroy', $membership->id))
        : $this->actingAs($admin)->put(route('workspaces.members.update', $membership->id), ['role' => 'member']);

    $response->assertInvalid([$method === 'delete' ? 'membership' : 'role' => 'Only an owner']);

    expect($membership->fresh()?->role)->toBe(WorkspaceRole::Owner)
        ->and($membership->fresh()?->status)->toBe(WorkspaceMembershipStatus::Active);
})->with(['delete', 'put']);

it('lists the invitations separately from the people', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    WorkspaceMembership::factory()
        ->invited($admin)
        ->unclaimed('nobody@example.com')
        ->create(['workspace_id' => $workspace->id]);
    memberOf($workspace, WorkspaceRole::Member, WorkspaceMembershipStatus::Declined);

    $this->actingAs($admin)
        ->get(route('workspaces.members'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('members', 1)
            ->has('invitations', 1)
            ->where('invitations.0.email', 'nobody@example.com')
            ->where('invitations.0.hasAccount', false)
            ->where('invitations.0.invitedBy', $admin->name));
});

it('keeps the invitations from somebody who cannot act on them', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    WorkspaceMembership::factory()
        ->invited($admin)
        ->unclaimed('nobody@example.com')
        ->create(['workspace_id' => $workspace->id]);
    $member = memberOf($workspace, WorkspaceRole::Member);

    $this->actingAs($member)
        ->get(route('workspaces.members'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('invitations', 0)
            ->where('can.manageMembers', false));
});

it('answers a malformed membership id with not found', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);

    $this->actingAs($admin)
        ->put(route('workspaces.members.update', 'not-a-uuid'), ['role' => 'member'])
        ->assertNotFound();

    $this->post(route('workspaces.members.resend', 'not-a-uuid'))->assertNotFound();
    $this->delete(route('workspaces.members.destroy', 'not-a-uuid'))->assertNotFound();
});
