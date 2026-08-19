<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $overrides
 */
function insertProjectMembership(Project $project, User $user, array $overrides = []): void
{
    DB::table('project_memberships')->insert([
        'id' => (string) Str::uuid7(),
        'project_id' => $project->id,
        'user_id' => $user->id,
        'access_level' => ProjectAccessLevel::Editor->value,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);
}

it('rejects a second membership for the same person in the same project', function (): void {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    insertProjectMembership($project, $user);

    expect(fn () => DB::transaction(fn () => insertProjectMembership($project, $user)))
        ->toThrow(QueryException::class);
});

it('allows the same person in two projects', function (): void {
    $user = User::factory()->create();

    insertProjectMembership(Project::factory()->create(), $user);
    insertProjectMembership(Project::factory()->create(), $user);

    expect(DB::table('project_memberships')->where('user_id', $user->id)->count())->toBe(2);
});

it('requires an access level the domain defines', function (?string $value): void {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $overrides = $value === null ? [] : ['access_level' => $value];

    if ($value === null) {
        expect(fn () => DB::transaction(fn () => DB::table('project_memberships')->insert([
            'id' => (string) Str::uuid7(),
            'project_id' => $project->id,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ])))->toThrow(QueryException::class);

        return;
    }

    expect(fn () => DB::transaction(fn () => insertProjectMembership($project, $user, $overrides)))
        ->toThrow(QueryException::class);
})->with([
    'missing' => null,
    'outside the enum' => 'admin',
]);

it('dies with its project', function (): void {
    $project = Project::factory()->create();
    insertProjectMembership($project, User::factory()->create());

    $project->forceDelete();

    expect(DB::table('project_memberships')->count())->toBe(0);
});

it('dies with its user', function (): void {
    $user = User::factory()->create();
    insertProjectMembership(Project::factory()->create(), $user);

    $user->delete();

    expect(DB::table('project_memberships')->count())->toBe(0);
});

it('survives a soft-deleted project, because the row is not gone', function (): void {
    $project = Project::factory()->create();
    insertProjectMembership($project, User::factory()->create());

    $project->delete();

    expect(DB::table('project_memberships')->count())->toBe(1);
});
