<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;

/**
 * @return array{Workspace, User, WorkspaceMembership}
 */
function matrixWorkspace(WorkspaceRole $role, WorkspaceMembershipStatus $status = WorkspaceMembershipStatus::Active): array
{
    $workspace = Workspace::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $actor = memberOf($workspace, $role, $status);

    $target = memberOf($workspace, WorkspaceRole::Guest);

    return [$workspace, $actor, $workspace->membershipFor($target)];
}

/**
 * @return array{string, string, array<string, mixed>}
 */
function matrixOperation(string $operation, Workspace $workspace, WorkspaceMembership $target): array
{
    return match ($operation) {
        'read settings' => ['get', route('workspaces.edit'), []],
        'update settings' => ['put', route('workspaces.update'), ['id' => $workspace->id, 'name' => 'Renamed']],
        'read members' => ['get', route('workspaces.members'), []],
        'invite member' => ['post', route('workspaces.members.store'), ['email' => 'invitee@example.com', 'role' => 'member']],
        'change role' => ['put', route('workspaces.members.update', $target->id), ['role' => 'member']],
        'remove member' => ['delete', route('workspaces.members.destroy', $target->id), []],
        default => throw new InvalidArgumentException("Unknown matrix operation [{$operation}]."),
    };
}

it('answers each role the same way at every endpoint', function (WorkspaceRole $role, string $operation, bool $allowed): void {
    [$workspace, $actor, $target] = matrixWorkspace($role);
    User::factory()->create(['email' => 'invitee@example.com']);

    [$method, $url, $payload] = matrixOperation($operation, $workspace, $target);

    $response = $this->actingAs($actor)->{$method}($url, $payload);

    $allowed
        ? expect($response->status())->toBeIn([200, 302])
        : $response->assertForbidden();

    if (! $allowed) {
        expect($workspace->fresh()?->name)->toBe('Acme')
            ->and($target->fresh()?->role)->toBe(WorkspaceRole::Guest)
            ->and($target->fresh()?->status)->toBe(WorkspaceMembershipStatus::Active);
    }
})->with([
    'owner reads settings' => [WorkspaceRole::Owner, 'read settings', true],
    'owner updates settings' => [WorkspaceRole::Owner, 'update settings', true],
    'owner reads members' => [WorkspaceRole::Owner, 'read members', true],
    'owner invites' => [WorkspaceRole::Owner, 'invite member', true],
    'owner changes a role' => [WorkspaceRole::Owner, 'change role', true],
    'owner removes' => [WorkspaceRole::Owner, 'remove member', true],

    'admin reads settings' => [WorkspaceRole::Admin, 'read settings', true],
    'admin updates settings' => [WorkspaceRole::Admin, 'update settings', true],
    'admin reads members' => [WorkspaceRole::Admin, 'read members', true],
    'admin invites' => [WorkspaceRole::Admin, 'invite member', true],
    'admin changes a role' => [WorkspaceRole::Admin, 'change role', true],
    'admin removes' => [WorkspaceRole::Admin, 'remove member', true],

    'member reads settings' => [WorkspaceRole::Member, 'read settings', true],
    'member updates settings' => [WorkspaceRole::Member, 'update settings', false],
    'member reads members' => [WorkspaceRole::Member, 'read members', true],
    'member invites' => [WorkspaceRole::Member, 'invite member', false],
    'member changes a role' => [WorkspaceRole::Member, 'change role', false],
    'member removes' => [WorkspaceRole::Member, 'remove member', false],

    'guest reads settings' => [WorkspaceRole::Guest, 'read settings', true],
    'guest updates settings' => [WorkspaceRole::Guest, 'update settings', false],
    'guest reads members' => [WorkspaceRole::Guest, 'read members', true],
    'guest invites' => [WorkspaceRole::Guest, 'invite member', false],
    'guest changes a role' => [WorkspaceRole::Guest, 'change role', false],
    'guest removes' => [WorkspaceRole::Guest, 'remove member', false],
]);

it('refuses every operation on a membership that is not active', function (WorkspaceMembershipStatus $status, string $operation): void {
    [$workspace, $actor, $target] = matrixWorkspace(WorkspaceRole::Owner, $status);
    User::factory()->create(['email' => 'invitee@example.com']);

    [$method, $url, $payload] = matrixOperation($operation, $workspace, $target);

    $response = $this->actingAs($actor)->{$method}($url, $payload);

    // Reads 404 because no workspace resolves; writes 403 because authorization runs before resolution.
    expect($response->status())->toBeIn([403, 404]);

    expect($workspace->fresh()?->name)->toBe('Acme')
        ->and($target->fresh()?->role)->toBe(WorkspaceRole::Guest)
        ->and($target->fresh()?->status)->toBe(WorkspaceMembershipStatus::Active);
})->with([
    'invited' => WorkspaceMembershipStatus::Invited,
    'declined' => WorkspaceMembershipStatus::Declined,
    'revoked' => WorkspaceMembershipStatus::Revoked,
    'expired' => WorkspaceMembershipStatus::Expired,
])->with([
    'read settings', 'update settings', 'read members', 'invite member', 'change role', 'remove member',
]);

it('refuses every operation to someone from another workspace', function (string $operation): void {
    [$workspace, , $target] = matrixWorkspace(WorkspaceRole::Owner);
    $outsider = memberOf(Workspace::factory()->create(['slug' => 'theirs']), WorkspaceRole::Owner);
    User::factory()->create(['email' => 'invitee@example.com']);

    [$method, $url, $payload] = matrixOperation($operation, $workspace, $target);

    $response = $this->actingAs($outsider)->{$method}($url, $payload);

    // The outsider's requests resolve their own workspace, so the status code alone proves nothing.
    expect($workspace->fresh()?->name)->toBe('Acme')
        ->and($target->fresh()?->role)->toBe(WorkspaceRole::Guest)
        ->and($target->fresh()?->status)->toBe(WorkspaceMembershipStatus::Active)
        ->and($response->status())->not->toBe(500);
})->with([
    'update settings', 'invite member', 'change role', 'remove member',
]);
