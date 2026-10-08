<?php

declare(strict_types=1);

use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

it('remembers the workspace a user was last in', function (): void {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);

    expect($user->currentWorkspace?->is($workspace))->toBeTrue();
});

it('keeps the account when its current workspace is deleted', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->ownedBy($owner)->create();
    $member = User::factory()->create(['current_workspace_id' => $workspace->id]);

    $workspace->delete();

    expect($member->fresh()?->exists)->toBeTrue()
        ->and($member->fresh()?->current_workspace_id)->toBeNull();
});

it('starts with no current workspace', function (): void {
    expect(User::factory()->create()->current_workspace_id)->toBeNull();
});
