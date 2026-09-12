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
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

it('lets an active workspace member subscribe to their workspace', function (): void {
    [$workspace, $member] = workspaceWith(WorkspaceRole::Member);

    $this->subscribeTo($member, "private-workspace.{$workspace->id}")
        ->assertOk()
        ->assertJsonStructure(['auth']);
});

it('refuses a guest the workspace channel', function (): void {
    [$workspace, $guest] = workspaceWith(WorkspaceRole::Guest);

    $this->subscribeTo($guest, "private-workspace.{$workspace->id}")->assertForbidden();
})->with([
    'the workspace channel carries tasks that sit in no project, which a guest may not see',
]);

it('refuses a membership that no longer grants access', function (WorkspaceMembershipStatus $status): void {
    [$workspace, $user] = workspaceWith(WorkspaceRole::Member, $status);

    $this->subscribeTo($user, "private-workspace.{$workspace->id}")->assertForbidden();
})->with([
    'invited' => WorkspaceMembershipStatus::Invited,
    'declined' => WorkspaceMembershipStatus::Declined,
    'revoked' => WorkspaceMembershipStatus::Revoked,
    'expired' => WorkspaceMembershipStatus::Expired,
]);

it('refuses a workspace to somebody who was never a member', function (): void {
    $workspace = Workspace::factory()->create();
    $outsider = User::factory()->create();

    $this->subscribeTo($outsider, "private-workspace.{$workspace->id}")->assertForbidden();
});

it('refuses another workspace to an owner of their own', function (): void {
    $theirs = Workspace::factory()->create();
    [, $owner] = workspaceWith(WorkspaceRole::Owner);

    $this->subscribeTo($owner, "private-workspace.{$theirs->id}")->assertForbidden();
});

it('lets a member subscribe to a workspace-visible project', function (): void {
    [$project, $member] = projectFor(WorkspaceRole::Member);

    $this->subscribeTo($member, "private-project.{$project->id}")->assertOk();
});

it('refuses a guest a workspace-visible project they were not given', function (): void {
    [$project, $guest] = projectFor(WorkspaceRole::Guest);

    $this->subscribeTo($guest, "private-project.{$project->id}")->assertNotFound();
})->with([
    'a project channel resolves through the same route binder the HTTP layer uses, so an
    invisible project is indistinguishable from one that does not exist — 403 would confirm
    the id',
]);

it('lets a guest subscribe to a private project they were given', function (): void {
    [$project, $guest] = projectFor(WorkspaceRole::Guest, ProjectAccessLevel::Viewer, ProjectVisibility::Private);

    $this->subscribeTo($guest, "private-project.{$project->id}")->assertOk();
});

it('refuses a private project to a workspace owner who was not given it', function (): void {
    [$project, $owner] = projectFor(WorkspaceRole::Owner, null, ProjectVisibility::Private);

    $this->subscribeTo($owner, "private-project.{$project->id}")->assertNotFound();
})->with([
    'a private project is private to the workspace, owners included (ADR-0006)',
]);

it('refuses a project grant that outlived its workspace membership', function (): void {
    [$project, $user] = projectFor(
        WorkspaceRole::Member,
        ProjectAccessLevel::Editor,
        ProjectVisibility::Private,
        WorkspaceMembershipStatus::Revoked,
    );

    $this->subscribeTo($user, "private-project.{$project->id}")->assertNotFound();
});

it('refuses another workspace project to a member of their own', function (): void {
    $elsewhere = Project::factory()->in(Workspace::factory()->create())->create();
    [, $member] = workspaceWith(WorkspaceRole::Member);

    $this->subscribeTo($member, "private-project.{$elsewhere->id}")->assertNotFound();
});

it('lets a user subscribe to their own channel and to nobody elses', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->subscribeTo($user, "private-user.{$user->id}")->assertOk();
    $this->subscribeTo($user, "private-user.{$other->id}")->assertForbidden();
});

it('refuses a user channel whose id is not a number without asking the database', function (): void {
    $user = User::factory()->create();

    $this->subscribeTo($user, 'private-user.not-a-number')->assertForbidden();
})->with([
    'users.id is a bigint, so a query would be a 500 rather than a refusal',
]);

it('refuses a channel for a subject that does not exist', function (): void {
    [, $member] = workspaceWith(WorkspaceRole::Member);
    $missing = (string) Str::uuid7();

    $this->subscribeTo($member, "private-workspace.{$missing}")->assertForbidden();
    $this->subscribeTo($member, "private-project.{$missing}")->assertNotFound();
})->with([
    'the codes differ because the resolutions do: a workspace binds implicitly and answers
    403, a project resolves through the binder in routes/projects.php and answers 404 —
    the same two answers the HTTP layer gives',
]);

it('refuses a channel whose id is not a uuid rather than erroring', function (): void {
    [, $member] = workspaceWith(WorkspaceRole::Member);

    $this->subscribeTo($member, 'private-workspace.not-a-uuid')->assertNotFound();
})->with([
    'the codes differ deliberately: a valid id nobody owns is 403, an id that cannot exist is 404',
]);

it('refuses a channel nothing declares', function (): void {
    [$project, $member] = projectFor(WorkspaceRole::Member);

    $this->subscribeTo($member, "private-task.{$project->id}")->assertForbidden();
});

it('refuses the framework default user channel, which nothing declares any more', function (): void {
    $user = User::factory()->create();

    $this->subscribeTo($user, "private-App.Models.User.{$user->id}")->assertForbidden();
})->with([
    'ADR-0008 names one user channel, and User::receivesBroadcastNotificationsOn returns it',
]);

it('refuses a subscription from an account that is not signed in', function (): void {
    $workspace = Workspace::factory()->create();

    $this->postJson('/broadcasting/auth', [
        'channel_name' => "private-workspace.{$workspace->id}",
        'socket_id' => '1234.5678',
    ])->assertUnauthorized();
});

it('refuses a subscription from an account that never verified its address', function (): void {
    $workspace = Workspace::factory()->create();
    $unverified = memberOf($workspace, WorkspaceRole::Member, user: User::factory()->unverified()->create());

    $this->subscribeTo($unverified, "private-workspace.{$workspace->id}")->assertForbidden();
})->with([
    'the socket is behind the same coarse gate the screens are',
]);

it('names one user channel, which the notification broadcast targets', function (): void {
    $user = User::factory()->create();

    expect($user->receivesBroadcastNotificationsOn(new class extends Notification {}))
        ->toBe("user.{$user->id}");
});

it('grants a project member a project their workspace membership alone would refuse', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Commenter)->create();

    $this->subscribeTo($guest, "private-project.{$project->id}")->assertOk();
});

// The `{project}` binding refuses unseen projects before the callback runs, so the callbacks are asserted directly.

it('answers the workspace channel by membership and role', function (): void {
    $callback = channelCallback('workspace.{workspace}');
    $workspace = Workspace::factory()->create();

    expect($callback(memberOf($workspace, WorkspaceRole::Owner), $workspace))->toBeTrue()
        ->and($callback(memberOf($workspace, WorkspaceRole::Admin), $workspace))->toBeTrue()
        ->and($callback(memberOf($workspace, WorkspaceRole::Member), $workspace))->toBeTrue()
        ->and($callback(memberOf($workspace, WorkspaceRole::Guest), $workspace))->toBeFalse()
        ->and($callback(User::factory()->create(), $workspace))->toBeFalse()
        ->and($callback(
            memberOf($workspace, WorkspaceRole::Member, WorkspaceMembershipStatus::Revoked),
            $workspace,
        ))->toBeFalse();
});

it('answers the project channel by the projects own visibility rule', function (): void {
    $callback = channelCallback('project.{project}');
    $workspace = Workspace::factory()->create();
    $visible = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Workspace]);
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);

    $member = memberOf($workspace, WorkspaceRole::Member);
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $granted = memberOf($workspace, WorkspaceRole::Guest);
    ProjectMembership::factory()->in($private)->forUser($granted)->withAccess(ProjectAccessLevel::Viewer)->create();

    expect($callback($member, $visible))->toBeTrue()
        ->and($callback($guest, $visible))->toBeFalse()
        ->and($callback($member, $private))->toBeFalse()
        ->and($callback($granted, $private))->toBeTrue()
        ->and($callback(User::factory()->create(), $visible))->toBeFalse();
});

it('answers the user channel by identity, comparing as text', function (): void {
    $callback = channelCallback('user.{userId}');
    $user = User::factory()->create();
    $other = User::factory()->create();

    expect($callback($user, (string) $user->id))->toBeTrue()
        ->and($callback($user, (string) $other->id))->toBeFalse()
        ->and($callback($user, 'not-a-number'))->toBeFalse()
        ->and($callback($user, $user->id.'abc'))->toBeFalse()
        ->and($callback($user, ' '.$user->id))->toBeFalse()
        ->and($callback($user, ''))->toBeFalse();
})->with([
    'users.id is a bigint: a model binding would ask PostgreSQL for a non-numeric id and
    get a 500 rather than a refusal, and an int cast would turn every unparseable id into 0',
]);

it('declares exactly the three channels ADR-0008 names', function (): void {
    expect(array_keys(broadcastChannels()))
        ->toEqualCanonicalizing(['workspace.{workspace}', 'project.{project}', 'user.{userId}']);
})->with([
    'a fourth channel is a fourth door, and this fails until somebody documents it here',
]);
