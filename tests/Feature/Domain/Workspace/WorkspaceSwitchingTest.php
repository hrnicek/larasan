<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{User, list<Workspace>}
 */
function memberOfWorkspaces(int $count): array
{
    $user = User::factory()->create();

    $workspaces = [];

    foreach (range(1, $count) as $index) {
        $workspace = Workspace::factory()->create(['slug' => "workspace-{$index}"]);

        memberOf($workspace, WorkspaceRole::Member, user: $user);

        $workspaces[] = $workspace;
    }

    return [$user, $workspaces];
}

it('switches to another workspace the actor belongs to', function (): void {
    [$user, [$first, $second]] = memberOfWorkspaces(2);
    $user->forceFill(['current_workspace_id' => $first->id])->save();

    $this->actingAs($user)
        ->post(route('workspaces.switch', $second->slug))
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()?->current_workspace_id)->toBe($second->id);
});

it('keeps the choice for the next request without a workspace in the url', function (): void {
    [$user, [, $second]] = memberOfWorkspaces(2);

    $this->actingAs($user)->post(route('workspaces.switch', $second->slug));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('workspace.slug', $second->slug));
});

it('survives a new session', function (): void {
    [$user, [, $second]] = memberOfWorkspaces(2);

    $this->actingAs($user)->post(route('workspaces.switch', $second->slug));

    $this->flushSession();

    $this->actingAs($user->refresh())
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('workspace.slug', $second->slug));
});

it('refuses to switch to a workspace the actor does not belong to', function (): void {
    [$user, [$first]] = memberOfWorkspaces(1);
    $user->forceFill(['current_workspace_id' => $first->id])->save();
    $other = Workspace::factory()->create(['slug' => 'somebody-else']);

    $this->actingAs($user)
        ->post(route('workspaces.switch', $other->slug))
        ->assertNotFound();

    expect($user->fresh()?->current_workspace_id)->toBe($first->id);
});

it('refuses to switch on a membership that is no longer active', function (): void {
    [$user, [$first]] = memberOfWorkspaces(1);
    $revoked = Workspace::factory()->create(['slug' => 'revoked']);

    WorkspaceMembership::factory()->withStatus(WorkspaceMembershipStatus::Revoked)->create([
        'workspace_id' => $revoked->id,
        'user_id' => $user->id,
    ]);
    $user->forceFill(['current_workspace_id' => $first->id])->save();

    $this->actingAs($user)->post(route('workspaces.switch', 'revoked'))->assertNotFound();

    expect($user->fresh()?->current_workspace_id)->toBe($first->id);
});
