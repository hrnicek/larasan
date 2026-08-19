<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Carbon\CarbonImmutable;

it('records who invited and when the invitation lapses', function (): void {
    $workspace = Workspace::factory()->create();
    $inviter = memberOf($workspace);
    $invitee = User::factory()->create();

    $membership = WorkspaceMembership::factory()->invited($inviter)->create([
        'workspace_id' => $workspace->id,
        'user_id' => $invitee->id,
    ]);

    expect($membership->invitedBy?->is($inviter))->toBeTrue()
        ->and($membership->expires_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($membership->joined_at)->toBeNull()
        ->and($membership->status)->toBe(WorkspaceMembershipStatus::Invited);
});

it('keeps the member when the inviter closes their account', function (): void {
    $workspace = Workspace::factory()->create();
    $inviter = User::factory()->create();
    $invitee = User::factory()->create();

    $membership = WorkspaceMembership::factory()->invited($inviter)->create([
        'workspace_id' => $workspace->id,
        'user_id' => $invitee->id,
    ]);

    $inviter->delete();

    expect($membership->fresh())->not->toBeNull()
        ->and($membership->fresh()?->invited_by)->toBeNull();
});

it('knows an invitation is past its deadline before any sweep has run', function (): void {
    $membership = WorkspaceMembership::factory()
        ->invited(expiresAt: CarbonImmutable::now()->subMinute())
        ->create();

    expect($membership->hasExpired())->toBeTrue()
        ->and($membership->status)->toBe(WorkspaceMembershipStatus::Invited)
        ->and($membership->status->canBeAccepted())->toBeTrue();
});

it('treats an accepted membership as having no deadline', function (): void {
    $membership = WorkspaceMembership::factory()->active()->create();

    expect($membership->expires_at)->toBeNull()
        ->and($membership->hasExpired())->toBeFalse();
});
