<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

// A refusal is 403 or 404 depending on how the channel subject binds; `ChannelAuthorizationTest` pins which.

/**
 * @return array{Workspace, User, Project}
 */
function channelSubject(WorkspaceRole $role, string $placement): array
{
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);

    $project = Project::factory()->in($workspace)->create([
        'visibility' => $placement === 'workspace' ? ProjectVisibility::Workspace : ProjectVisibility::Private,
    ]);

    if ($placement === 'private given') {
        ProjectMembership::factory()->in($project)->forUser($actor)->withAccess(ProjectAccessLevel::Viewer)->create();
    }

    return [$workspace, $actor, $project];
}

it('opens the workspace channel to members and to nobody else', function (
    WorkspaceRole $role,
    bool $allowed,
): void {
    [$workspace, $actor] = channelSubject($role, 'workspace');

    $response = $this->subscribeTo($actor, "private-workspace.{$workspace->id}");

    $allowed
        ? $response->assertOk()
        : expect($response->status())->toBe(403);
})->with([
    'owner' => [WorkspaceRole::Owner, true],
    'admin' => [WorkspaceRole::Admin, true],
    'member' => [WorkspaceRole::Member, true],
    // The workspace channel carries tasks outside any project, which guests may not see.
    'guest' => [WorkspaceRole::Guest, false],
]);

it('opens a project channel to exactly the people who can open the project', function (
    WorkspaceRole $role,
    string $placement,
    bool $allowed,
): void {
    [, $actor, $project] = channelSubject($role, $placement);

    $response = $this->subscribeTo($actor, "private-project.{$project->id}");

    $allowed
        ? $response->assertOk()
        : expect($response->status())->toBeIn([403, 404]);

    // The `{project}` binding refuses unseen projects before the callback runs, so the callback is asserted directly.
    expect(channelCallback('project.{project}')($actor, $project))->toBe($allowed);
})->with([
    'owner, workspace project' => [WorkspaceRole::Owner, 'workspace', true],
    'admin, workspace project' => [WorkspaceRole::Admin, 'workspace', true],
    'member, workspace project' => [WorkspaceRole::Member, 'workspace', true],
    'guest, workspace project' => [WorkspaceRole::Guest, 'workspace', false],

    'owner, private project they were given' => [WorkspaceRole::Owner, 'private given', true],
    'admin, private project they were given' => [WorkspaceRole::Admin, 'private given', true],
    'member, private project they were given' => [WorkspaceRole::Member, 'private given', true],
    'guest, private project they were given' => [WorkspaceRole::Guest, 'private given', true],

    'owner, private project they were not' => [WorkspaceRole::Owner, 'private', false],
    'admin, private project they were not' => [WorkspaceRole::Admin, 'private', false],
    'member, private project they were not' => [WorkspaceRole::Member, 'private', false],
    'guest, private project they were not' => [WorkspaceRole::Guest, 'private', false],
]);

it('refuses another workspace to every role', function (WorkspaceRole $role): void {
    [, $actor] = channelSubject($role, 'workspace');
    $elsewhere = Workspace::factory()->create();
    $theirProject = Project::factory()->in($elsewhere)->create();

    expect($this->subscribeTo($actor, "private-workspace.{$elsewhere->id}")->status())->toBe(403)
        ->and($this->subscribeTo($actor, "private-project.{$theirProject->id}")->status())->toBeIn([403, 404]);
})->with([
    'owner' => [WorkspaceRole::Owner],
    'admin' => [WorkspaceRole::Admin],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it('closes both channels the moment a membership stops granting access', function (
    WorkspaceMembershipStatus $status,
): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member, $status);
    $project = Project::factory()->in($workspace)->create();

    expect($this->subscribeTo($actor, "private-workspace.{$workspace->id}")->status())->toBe(403)
        ->and($this->subscribeTo($actor, "private-project.{$project->id}")->status())->toBeIn([403, 404]);
})->with([
    'invited' => [WorkspaceMembershipStatus::Invited],
    'declined' => [WorkspaceMembershipStatus::Declined],
    'revoked' => [WorkspaceMembershipStatus::Revoked],
    'expired' => [WorkspaceMembershipStatus::Expired],
]);

it('opens a user channel to that user alone', function (WorkspaceRole $role): void {
    [, $actor] = channelSubject($role, 'workspace');
    $somebodyElse = User::factory()->create();

    $this->subscribeTo($actor, "private-user.{$actor->id}")->assertOk();

    expect($this->subscribeTo($actor, "private-user.{$somebodyElse->id}")->status())->toBe(403);
})->with([
    'owner' => [WorkspaceRole::Owner],
    'admin' => [WorkspaceRole::Admin],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it('refuses every channel to somebody who is not signed in', function (): void {
    $workspace = Workspace::factory()->create();
    $project = Project::factory()->in($workspace)->create();
    $user = User::factory()->create();

    foreach ([
        "private-workspace.{$workspace->id}",
        "private-project.{$project->id}",
        "private-user.{$user->id}",
    ] as $channel) {
        $this->postJson('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678'])
            ->assertUnauthorized();
    }
});
