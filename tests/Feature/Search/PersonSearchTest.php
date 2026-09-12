<?php

declare(strict_types=1);

use App\Domain\Search\Queries\PersonResults;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Scout\Jobs\MakeSearchable;

/**
 * The collection engine matches substrings, so Faker names and emails could collide with the search term.
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
        ->toEqualCanonicalizing(['id', 'name', 'email', 'avatar', 'role']);
})->with([
    'a user row carries a password hash, two-factor secrets and recovery codes',
]);

it('does not queue an indexing job when only the current workspace changed', function (): void {
    $workspace = Workspace::factory()->create();
    $person = pinnedMemberOf($workspace, 'Jana Nováková');

    Queue::fake();

    // Reloaded because `wasRecentlyCreated` stays true on the creating instance and forces indexing.
    $person = User::findOrFail($person->id);

    $person->forceFill(['current_workspace_id' => $workspace->id])->save();
    Queue::assertNothingPushed();

    $person->update(['name' => 'Jana Svobodová']);
    Queue::assertPushed(MakeSearchable::class);
})->with([
    'a person moving between two workspaces would otherwise queue a job per move, for a document
    whose two fields did not change',
]);

it('finds a colleague with their face, not only their initials', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = pinnedMemberOf($workspace, 'Actor Zero');
    memberOf($workspace, user: User::factory()->withAvatarPreset(11)->create([
        'name' => 'Jana Nováková',
        'email' => 'jana-novakova@pinned.test',
    ]));

    $results = personResults($workspace, $actor, 'Jana');

    expect($results)->toHaveCount(1)
        ->and($results[0]['avatar'])->toBe(asset('img/avatars/11.svg'));
});
