<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Payloads\PersonSummary;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

it('draws a person the way every screen draws one', function (): void {
    $person = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);

    expect(PersonSummary::from($person))->toBe([
        'id' => $person->id,
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'avatar' => null,
    ]);
});

it('answers nobody for a field that is allowed to be empty', function (): void {
    expect(PersonSummary::fromNullable(null))->toBeNull()
        ->and(PersonSummary::fromNullable(User::factory()->create()))->toHaveKeys(['id', 'name', 'email', 'avatar']);
});

it('withholds the address from a face', function (): void {
    $person = User::factory()->create(['name' => 'Ada Lovelace']);

    expect(PersonSummary::face($person))->toBe([
        'id' => $person->id,
        'name' => 'Ada Lovelace',
        'avatar' => null,
    ]);
});

// Strict Eloquent throws on attributes that were not selected, so a missing column fails here.
it('reads only the columns a header list selects', function (): void {
    $person = User::factory()->withAvatarPreset(12)->create();

    $narrow = User::query()->whereKey($person->id)->get(PersonSummary::faceColumns('users'))->sole();

    expect(PersonSummary::face($narrow))->toBe([
        'id' => $person->id,
        'name' => $person->name,
        'avatar' => asset('img/avatars/12.svg'),
    ]);
});

it('reads only the columns a list of people selects', function (): void {
    $person = User::factory()->withAvatarPreset(12)->create();

    $narrow = User::query()->whereKey($person->id)->get(PersonSummary::columns())->sole();

    expect(PersonSummary::from($narrow)['avatar'])->toBe(asset('img/avatars/12.svg'));
});

it('draws a person with their address only for a reader who is not a guest', function (WorkspaceRole $role, WorkspaceMembershipStatus $status, bool $revealed): void {
    $workspace = Workspace::factory()->create();
    $reader = memberOf($workspace, $role, $status);
    $person = memberOf($workspace, user: User::factory()->create(['email' => 'ada@example.test']));

    $summary = PersonSummary::for($workspace, $reader);

    expect(array_key_exists('email', $summary->of($person)))->toBe($revealed)
        ->and($summary->ofNullable(null))->toBeNull();
})->with([
    'an owner' => [WorkspaceRole::Owner, WorkspaceMembershipStatus::Active, true],
    'a member' => [WorkspaceRole::Member, WorkspaceMembershipStatus::Active, true],
    'a guest' => [WorkspaceRole::Guest, WorkspaceMembershipStatus::Active, false],
    'a revoked member' => [WorkspaceRole::Member, WorkspaceMembershipStatus::Revoked, false],
]);

it('withholds the address from somebody outside the workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $person = memberOf($workspace);

    expect(PersonSummary::for($workspace, User::factory()->create())->of($person))->not->toHaveKey('email');
});
