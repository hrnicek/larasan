<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
| Shared helpers. Pest loads every test file into one process, so a helper declared at
| file scope in two files is a fatal redeclaration.
*/

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

function memberOf(
    Workspace $workspace,
    WorkspaceRole $role = WorkspaceRole::Member,
    WorkspaceMembershipStatus $status = WorkspaceMembershipStatus::Active,
    ?User $user = null,
): User {
    $user = $user ?? User::factory()->create();

    WorkspaceMembership::factory()->withStatus($status)->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

/**
 * @return array{Workspace, User}
 */
function workspaceWith(
    WorkspaceRole $role,
    WorkspaceMembershipStatus $status = WorkspaceMembershipStatus::Active,
): array {
    $workspace = Workspace::factory()->create();

    return [$workspace, memberOf($workspace, $role, $status)];
}

/**
 * @return array{Project, User}
 */
function projectFor(
    WorkspaceRole $role,
    ?ProjectAccessLevel $access = null,
    ProjectVisibility $visibility = ProjectVisibility::Workspace,
    WorkspaceMembershipStatus $status = WorkspaceMembershipStatus::Active,
): array {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);
    $project = Project::factory()->in($workspace)->create(['visibility' => $visibility]);

    if ($access !== null) {
        ProjectMembership::factory()->in($project)->forUser($actor)->withAccess($access)->create();
    }

    // Applied last: a project membership can only be created for an active workspace member.
    if ($status !== WorkspaceMembershipStatus::Active) {
        $workspace->membershipFor($actor)?->forceFill(['status' => $status])->save();
    }

    return [$project, $actor];
}

/**
 * A member without a membership row inherits the project's `default_access_level`, so a
 * restriction needs an explicit row.
 */
function viewerOf(Project $project, ProjectAccessLevel $access = ProjectAccessLevel::Viewer): User
{
    $user = memberOf($project->workspace, WorkspaceRole::Member);

    ProjectMembership::factory()->in($project)->forUser($user)->withAccess($access)->create();

    return $user;
}

/**
 * The suite's `null` broadcaster authorizes every subscription, so channels are registered on
 * Reverb, whose auth runs locally. `Broadcast::channel()` binds to the driver that was default
 * when the file first ran, hence the second require.
 *
 * @return array<string, Closure>
 */
function broadcastChannels(): array
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'channel-authorization-key',
        'broadcasting.connections.reverb.secret' => 'channel-authorization-secret',
        'broadcasting.connections.reverb.app_id' => 'channel-authorization-app',
    ]);

    require base_path('routes/channels.php');

    return Broadcast::driver()->getChannels()->all();
}

/**
 * The `{project}` route binding already refuses unseen projects, so only calling the callback
 * directly catches one that always returns `true`.
 */
function channelCallback(string $pattern): Closure
{
    $callback = broadcastChannels()[$pattern] ?? null;

    if (! $callback instanceof Closure) {
        throw new InvalidArgumentException("No channel is declared for [{$pattern}].");
    }

    return $callback;
}

/**
 * @param  list<array<string, mixed>>  $content
 * @return array<string, mixed>
 */
function doc(array $content): array
{
    return ['type' => 'doc', 'content' => $content];
}

/**
 * @param  list<array<string, mixed>>  $marks
 * @return array<string, mixed>
 */
function textNode(string $text, array $marks = []): array
{
    return $marks === []
        ? ['type' => 'text', 'text' => $text]
        : ['type' => 'text', 'text' => $text, 'marks' => $marks];
}

function mentionOf(User $user, ?string $name = null): string
{
    return '@['.($name ?? $user->name).'](user:'.$user->id.')';
}
