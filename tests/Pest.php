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
    $actor = memberOf($workspace, $role, $status);
    $project = Project::factory()->in($workspace)->create(['visibility' => $visibility]);

    if ($access !== null) {
        ProjectMembership::factory()->in($project)->forUser($actor)->withAccess($access)->create();
    }

    return [$project, $actor];
}
