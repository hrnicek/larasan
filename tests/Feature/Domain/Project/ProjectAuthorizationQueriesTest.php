<?php

declare(strict_types=1);

use App\Domain\Shared\Access\MembershipRegistry;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Actions\ChangeWorkspaceMemberRole;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * @return list<string>
 */
function queriesDuring(Closure $work): array
{
    $sql = [];

    DB::listen(function (QueryExecuted $query) use (&$sql): void {
        $sql[] = $query->sql;
    });

    $work();

    return $sql;
}

/**
 * @param  list<string>  $queries
 */
function countAgainst(array $queries, string $table): int
{
    return count(array_filter($queries, fn (string $sql): bool => str_contains($sql, '"'.$table.'"')));
}

/**
 * @param  list<string>  $queries
 */
function directLookupsOf(array $queries, string $table): int
{
    return count(array_filter(
        $queries,
        fn (string $sql): bool => str_starts_with($sql, 'select * from "'.$table.'"'),
    ));
}

it('reads each membership once for a whole settings request', function (): void {
    [$project, $actor] = projectEditableBy(ProjectAccessLevel::Owner, WorkspaceRole::Owner);

    $queries = queriesDuring(function () use ($project, $actor): void {
        $this->actingAs($actor)->get(route('projects.edit', $project))->assertOk();
    });

    // The other matches are workspace resolution, the switcher and the visibility subqueries.
    expect(directLookupsOf($queries, 'workspace_memberships'))->toBe(1)
        ->and(directLookupsOf($queries, 'project_memberships'))->toBe(1)
        ->and(countAgainst($queries, 'workspace_memberships'))->toBe(3)
        ->and(countAgainst($queries, 'project_memberships'))->toBe(3);
});

it('reads each membership once per actor, not once for everybody', function (): void {
    [$project, $actor] = projectEditableBy(ProjectAccessLevel::Editor, WorkspaceRole::Member);
    $other = memberOf($project->workspace, WorkspaceRole::Member);

    $registry = app(MembershipRegistry::class);

    $queries = queriesDuring(function () use ($registry, $project, $actor, $other): void {
        $registry->forWorkspace($project->workspace, $actor);
        $registry->forWorkspace($project->workspace, $actor);
        $registry->forWorkspace($project->workspace, $other);
    });

    expect(countAgainst($queries, 'workspace_memberships'))->toBe(2);
});

it('remembers that somebody is not a member without asking twice', function (): void {
    $workspace = Workspace::factory()->createOne();
    $stranger = memberOf(Workspace::factory()->createOne());

    $queries = queriesDuring(function () use ($workspace, $stranger): void {
        $workspace->membershipFor($stranger);
        $workspace->membershipFor($stranger);
    });

    // A null answer must be memoised too; ??= would query again on every call.
    expect(countAgainst($queries, 'workspace_memberships'))->toBe(1);
});

it('forgets an answer the moment the membership changes', function (): void {
    $workspace = Workspace::factory()->createOne();
    $owner = memberOf($workspace, WorkspaceRole::Owner);
    $member = memberOf($workspace, WorkspaceRole::Member);

    expect($workspace->membershipFor($member)?->role)->toBe(WorkspaceRole::Member);

    app(ChangeWorkspaceMemberRole::class)->handle(
        $workspace,
        $owner,
        $workspace->membershipFor($member) ?? throw new RuntimeException('missing membership'),
        WorkspaceRole::Admin,
    );

    // No nullsafe: the ?? throw above already tells PHPStan this cannot be null.
    expect($workspace->membershipFor($member)->role)->toBe(WorkspaceRole::Admin);
});

it('forgets an answer when a membership is deleted', function (): void {
    [$project, $actor] = projectEditableBy(ProjectAccessLevel::Editor, WorkspaceRole::Member);

    expect($project->memberFor($actor))->not->toBeNull();

    $project->memberships()->where('user_id', $actor->id)->get()->each->delete();

    expect($project->memberFor($actor))->toBeNull()
        ->and($project->allowsChangesBy($actor, Capability::TaskUpdate))->toBeFalse();
});
