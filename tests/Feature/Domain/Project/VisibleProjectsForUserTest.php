<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * @return list<string>
 */
function visibleProjectNames(Workspace $workspace, User $user, bool $includeArchived = false): array
{
    $names = [];

    foreach (app(VisibleProjectsForUser::class)($workspace, $user, $includeArchived) as $project) {
        $names[] = $project->name;
    }

    return $names;
}

it('shows a member the workspace-visible projects and the private ones they are in', function (): void {
    $workspace = Workspace::factory()->create();
    $member = memberOf($workspace, WorkspaceRole::Member);

    Project::factory()->in($workspace)->create(['name' => 'Open']);
    Project::factory()->in($workspace)->private()->create(['name' => 'Theirs']);
    $mine = Project::factory()->in($workspace)->private()->create(['name' => 'Mine']);

    ProjectMembership::factory()->in($mine)->forUser($member)->create();

    expect(visibleProjectNames($workspace, $member))->toBe(['Mine', 'Open']);
});

it('shows a guest only what they were explicitly given', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);

    Project::factory()->in($workspace)->create(['name' => 'Open']);
    $shared = Project::factory()->in($workspace)->private()->create(['name' => 'Shared']);

    ProjectMembership::factory()->in($shared)->forUser($guest)
        ->withAccess(ProjectAccessLevel::Commenter)->create();

    expect(visibleProjectNames($workspace, $guest))->toBe(['Shared']);
});

it('hides everything from someone whose workspace membership is not active', function (WorkspaceMembershipStatus $status): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Owner);
    $project = Project::factory()->in($workspace)->create(['name' => 'Open']);

    // Granted while they were a member, and then the workspace membership lapses — which is
    // the only way this row can exist at all (TASK-040-021). A live project membership must
    // not survive the workspace one.
    ProjectMembership::factory()->in($project)->forUser($actor)->create();

    $workspace->membershipFor($actor)?->forceFill(['status' => $status])->save();

    expect(visibleProjectNames($workspace, $actor))->toBe([]);
})->with([
    'invited' => WorkspaceMembershipStatus::Invited,
    'revoked' => WorkspaceMembershipStatus::Revoked,
    'expired' => WorkspaceMembershipStatus::Expired,
]);

it('hides everything from someone in another workspace', function (): void {
    $theirs = Workspace::factory()->create();
    Project::factory()->in($theirs)->create(['name' => 'Theirs']);
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    expect(visibleProjectNames($theirs, $outsider))->toBe([]);
});

it('leaves archived projects out unless they are asked for', function (): void {
    $workspace = Workspace::factory()->create();
    $member = memberOf($workspace, WorkspaceRole::Member);

    Project::factory()->in($workspace)->create(['name' => 'Active']);
    Project::factory()->in($workspace)->archived()->create(['name' => 'Archived']);

    expect(visibleProjectNames($workspace, $member))->toBe(['Active'])
        ->and(visibleProjectNames($workspace, $member, includeArchived: true))->toBe(['Active', 'Archived']);
});

it('leaves soft-deleted projects out entirely', function (): void {
    $workspace = Workspace::factory()->create();
    $member = memberOf($workspace, WorkspaceRole::Member);

    Project::factory()->in($workspace)->create(['name' => 'Active']);
    Project::factory()->in($workspace)->create(['name' => 'Deleted'])->delete();

    expect(visibleProjectNames($workspace, $member, includeArchived: true))->toBe(['Active']);
});

it('agrees with the policy for every combination', function (): void {
    $workspace = Workspace::factory()->create();

    $projects = [
        'open' => Project::factory()->in($workspace)->create(['name' => 'Open']),
        'private' => Project::factory()->in($workspace)->private()->create(['name' => 'Private']),
    ];

    foreach ([WorkspaceRole::Owner, WorkspaceRole::Admin, WorkspaceRole::Member, WorkspaceRole::Guest] as $role) {
        foreach ([null, ProjectAccessLevel::Viewer] as $access) {
            $actor = memberOf($workspace, $role);

            if ($access !== null) {
                foreach ($projects as $project) {
                    ProjectMembership::factory()->in($project)->forUser($actor)->withAccess($access)->create();
                }
            }

            $listed = visibleProjectNames($workspace, $actor);

            foreach ($projects as $project) {
                expect(in_array($project->name, $listed, true))
                    ->toBe($project->fresh()?->isVisibleTo($actor));
            }
        }
    }
});

it('answers in one query however many projects there are', function (): void {
    $workspace = Workspace::factory()->create();
    $member = memberOf($workspace, WorkspaceRole::Member);
    Project::factory()->in($workspace)->count(5)->create();

    // membershipFor() is one query, the listing is the second. The count must not grow
    // with the number of projects — asking the model per row would be the N+1 this query
    // object exists to prevent.
    DB::enableQueryLog();
    visibleProjectNames($workspace, $member);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(2);
});
