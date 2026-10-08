<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

it('does not let an account that never proved the invited mailbox take over the invitation', function (): void {
    Notification::fake();
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);

    $this->actingAs($admin)
        ->post(route('workspaces.members.store'), ['email' => 'new.hire@corp.com', 'role' => 'admin'])
        ->assertRedirect(route('workspaces.members'));

    $invitation = $workspace->memberships()->where('email', 'new.hire@corp.com')->sole();
    Auth::logout();

    $this->post(route('register.store'), [
        'name' => 'Attacker',
        'email' => 'new.hire@corp.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect();

    $attacker = User::query()->where('email', 'new.hire@corp.com')->sole();

    $this->actingAs($attacker)
        ->patch(route('profile.update'), ['name' => 'Attacker', 'email' => 'attacker@evil.com'])
        ->assertSessionHasNoErrors();

    $this->actingAs($attacker->refresh())
        ->get(URL::temporarySignedRoute(
            'verification.verify',
            CarbonImmutable::now()->addHour(),
            ['id' => $attacker->id, 'hash' => sha1('attacker@evil.com')],
        ));

    expect($attacker->refresh()->hasVerifiedEmail())->toBeTrue();

    $this->actingAs($attacker)
        ->post(route('workspaces.invitations.accept', ['membership' => $invitation->id]))
        ->assertNotFound();

    expect($invitation->fresh()?->status)->toBe(WorkspaceMembershipStatus::Invited)
        ->and($invitation->fresh()?->user_id)->toBeNull()
        ->and($attacker->fresh()?->workspaces()->count())->toBe(0);
});

it('lets the owner of the mailbox accept after registering and verifying it', function (): void {
    Notification::fake();
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $invitation = WorkspaceMembership::factory()
        ->invited($admin)
        ->unclaimed('new.hire@corp.com')
        ->create(['workspace_id' => $workspace->id]);

    $this->post(route('register.store'), [
        'name' => 'New Hire',
        'email' => 'new.hire@corp.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect();

    $hire = User::query()->where('email', 'new.hire@corp.com')->sole();

    $this->actingAs($hire)
        ->get(URL::temporarySignedRoute(
            'verification.verify',
            CarbonImmutable::now()->addHour(),
            ['id' => $hire->id, 'hash' => sha1('new.hire@corp.com')],
        ));

    $this->actingAs($hire->refresh())
        ->post(route('workspaces.invitations.accept', ['membership' => $invitation->id]))
        ->assertRedirect(route('dashboard'));

    expect($invitation->fresh()?->status)->toBe(WorkspaceMembershipStatus::Active)
        ->and($invitation->fresh()?->user_id)->toBe($hire->id);
});

it('refuses a claim that was bound before the account moved to another address', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $attacker = User::factory()->create(['email' => 'attacker@evil.com']);
    $invitation = WorkspaceMembership::factory()
        ->invited($admin)
        ->create([
            'workspace_id' => $workspace->id,
            'user_id' => $attacker->id,
            'email' => 'new.hire@corp.com',
            'role' => WorkspaceRole::Admin,
        ]);

    $this->actingAs($attacker)
        ->from(route('workspaces.index'))
        ->post(route('workspaces.invitations.accept', ['membership' => $invitation->id]))
        ->assertRedirect(route('workspaces.index'))
        ->assertSessionHasErrors('refusal');

    expect($invitation->fresh()?->status)->toBe(WorkspaceMembershipStatus::Invited)
        ->and($attacker->fresh()?->workspaces()->count())->toBe(0);
});
