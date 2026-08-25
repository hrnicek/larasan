<?php

declare(strict_types=1);

use App\Domain\Search\Queries\PersonResults;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * A person whose name and email cannot collide with the term a test searches for.
 *
 * Faker generates both, and the collection engine the suite runs on matches substrings — a
 * random `janae@example.org` is enough to make "find one colleague" find two, on one run in
 * fifty and never again.
 */
function pinnedMemberOf(Workspace $workspace, string $name): User
{
    return memberOf($workspace, user: User::factory()->create([
        'name' => $name,
        'email' => Str::slug($name).'@pinned.test',
    ]));
}

/**
 * @return list<array<string, mixed>>
 */
function personResults(Workspace $workspace, User $actor, string $term, int $limit = 5): array
{
    return app(PersonResults::class)($workspace, $actor, $term, $limit);
}

it('finds a colleague by name', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = pinnedMemberOf($workspace, 'Actor Zero');
    pinnedMemberOf($workspace, 'Jana Nováková');
    pinnedMemberOf($workspace, 'Petr Svoboda');

    $results = personResults($workspace, $actor, 'Jana');

    expect($results)->toHaveCount(1)
        ->and($results[0]['name'])->toBe('Jana Nováková');
});

it('finds a colleague by email', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = pinnedMemberOf($workspace, 'Actor Zero');
    memberOf($workspace, user: User::factory()->create([
        'name' => 'Jana Nováková',
        'email' => 'jana@example.test',
    ]));

    expect(personResults($workspace, $actor, 'jana@example.test'))->toHaveCount(1);
});

it('never returns somebody who belongs to another workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = pinnedMemberOf($workspace, 'Actor Zero');
    $elsewhere = Workspace::factory()->create();
    pinnedMemberOf($elsewhere, 'Jana Nováková');

    expect(personResults($workspace, $actor, 'Jana'))->toBe([]);
})->with([
    'the index holds every user in the installation, so the join is the boundary',
]);

it('never returns somebody whose membership no longer grants access', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = pinnedMemberOf($workspace, 'Actor Zero');
    memberOf(
        $workspace,
        WorkspaceRole::Member,
        WorkspaceMembershipStatus::Revoked,
        User::factory()->create(['name' => 'Jana Nováková', 'email' => 'jana@pinned.test']),
    );

    expect(personResults($workspace, $actor, 'Jana'))->toBe([]);
});

it('refuses the whole list to somebody who is not in the workspace', function (): void {
    $workspace = Workspace::factory()->create();
    pinnedMemberOf($workspace, 'Jana Nováková');
    $outsider = User::factory()->create(['name' => 'Outsider', 'email' => 'outsider@pinned.test']);

    expect(personResults($workspace, $outsider, 'Jana'))->toBe([]);
});

it('includes the person doing the searching', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = pinnedMemberOf($workspace, 'Jana Nováková');

    expect(personResults($workspace, $actor, 'Jana'))->toHaveCount(1);
})->with([
    '"assign to me" is a thing people search for',
]);

it('says which role a person holds here', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = pinnedMemberOf($workspace, 'Actor Zero');
    memberOf($workspace, WorkspaceRole::Guest, user: User::factory()->create([
        'name' => 'Jana Nováková',
        'email' => 'jana@pinned.test',
    ]));

    expect(personResults($workspace, $actor, 'Jana')[0]['role'])->toBe(WorkspaceRole::Guest->value);
});

it('ships no more of a user row than a result draws', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = pinnedMemberOf($workspace, 'Jana Nováková');

    expect(array_keys(personResults($workspace, $actor, 'Jana')[0]))
        ->toEqualCanonicalizing(['id', 'name', 'email', 'role']);
})->with([
    'a user row carries a password hash, two-factor secrets and recovery codes',
]);
