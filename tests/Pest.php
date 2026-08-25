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
| Only Feature tests get a database. Unit is reserved for logic that needs none —
| enums, data objects, ordering arithmetic, cycle detection — so it stays fast. A test
| that touches Eloquent, a factory or a migration belongs in Feature, whatever it is
| testing.
*/

/*
| Shared helpers. Pest loads every test file into the global function namespace in one
| process, so a helper defined at file scope in two files is a fatal redeclaration that
| takes down the whole run rather than a failing test. Anything more than one file needs
| belongs here.
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

/**
 * A user with a membership in the given workspace. Defaults to the case most tests
 * want: an active member.
 */
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
 * A workspace and a user who belongs to it in the given role.
 *
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

    /*
     * The status is applied last, because a project membership can only be created for an
     * active workspace member (TASK-040-021) — and a lapsed membership with a live project
     * grant is exactly the shape these tests are about.
     */
    if ($status !== WorkspaceMembershipStatus::Active) {
        $workspace->membershipFor($actor)?->forceFill(['status' => $status])->save();
    }

    return [$project, $actor];
}

/**
 * Point the application at the broadcast connection production uses and register the
 * channel callbacks on it.
 *
 * The suite's default connection is `null`, whose `auth()` decides nothing and would let
 * every subscription through whatever `routes/channels.php` says. Reverb is the Pusher
 * broadcaster, and its auth path is entirely local — it runs the channel callback and
 * signs the answer with HMAC, reaching no server. `Broadcast::channel()` registers on
 * whichever driver was the default when the file first ran, so the file is read again
 * here: it is the subject of these tests, not a workaround (TASK-170-002).
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
 * The authorization callback registered for a channel pattern, asked directly.
 *
 * Necessary rather than redundant: `{project}` resolves through the explicit route binder
 * in `routes/projects.php`, which already refuses a project the actor may not see. A
 * callback that answered `true` unconditionally would therefore pass every request-level
 * test in this suite, and this is what catches it.
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
 * A page document, and one run of text inside it. Wanted by the sanitizer's unit tests and by
 * every test that writes a page, so they live here rather than at file scope in several.
 *
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
