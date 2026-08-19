<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Carbon\CarbonImmutable;

it('casts the role and status to enums', function (): void {
    $membership = WorkspaceMembership::factory()->owner()->create();

    expect($membership->role)->toBe(WorkspaceRole::Owner)
        ->and($membership->status)->toBe(WorkspaceMembershipStatus::Active)
        ->and($membership->joined_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('relates to its workspace and user', function (): void {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    $membership = WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    expect($membership->workspace->is($workspace))->toBeTrue()
        ->and($membership->user->is($user))->toBeTrue();
});

it('leaves joined_at empty for a membership that has not been accepted', function (): void {
    $membership = WorkspaceMembership::factory()
        ->withStatus(WorkspaceMembershipStatus::Invited)
        ->create();

    expect($membership->joined_at)->toBeNull()
        ->and($membership->status->grantsAccess())->toBeFalse();
});

it('reads back the enum values that were persisted', function (): void {
    $membership = WorkspaceMembership::factory()->guest()->create();

    $stored = WorkspaceMembership::query()->findOrFail($membership->id);

    expect($stored->role)->toBe(WorkspaceRole::Guest)
        ->and($stored->getRawOriginal('role'))->toBe('guest');
});

it('grants a capability the role carries to an active membership', function (): void {
    $membership = WorkspaceMembership::factory()->member()->active()->create();

    expect($membership->allows(Capability::ProjectCreate))->toBeTrue();
});

it('denies a capability the role does not carry', function (): void {
    $membership = WorkspaceMembership::factory()->guest()->active()->create();

    expect($membership->allows(Capability::TaskCreate))->toBeFalse()
        ->and($membership->allows(Capability::CommentCreate))->toBeTrue();
});

it('denies every capability while the membership is not active', function (WorkspaceMembershipStatus $status): void {
    $membership = WorkspaceMembership::factory()->owner()->withStatus($status)->create();

    expect($membership->allows(Capability::WorkspaceManage))->toBeFalse()
        ->and($membership->allows(Capability::CommentCreate))->toBeFalse()
        ->and($membership->role->allows(Capability::WorkspaceManage))->toBeTrue();
})->with([
    'invited' => WorkspaceMembershipStatus::Invited,
    'declined' => WorkspaceMembershipStatus::Declined,
    'revoked' => WorkspaceMembershipStatus::Revoked,
    'expired' => WorkspaceMembershipStatus::Expired,
]);

it('denies the workspace delete capability to an admin', function (): void {
    $membership = WorkspaceMembership::factory()->admin()->active()->create();

    expect($membership->allows(Capability::WorkspaceDelete))->toBeFalse()
        ->and($membership->allows(Capability::WorkspaceManage))->toBeTrue();
});
