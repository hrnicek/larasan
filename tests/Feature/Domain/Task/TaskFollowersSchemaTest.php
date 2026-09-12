<?php

declare(strict_types=1);

use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function insertFollow(Task $task, User $user): string
{
    $id = (string) Str::uuid7();

    DB::table('task_followers')->insert([
        'id' => $id,
        'task_id' => $task->id,
        'user_id' => $user->id,
        'created_at' => now(),
    ]);

    return $id;
}

it('lets somebody follow a task once', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $user = memberOf($workspace);

    insertFollow($task, $user);

    // Savepoint: PostgreSQL aborts the whole transaction on a failed statement.
    expect(fn (): string => DB::transaction(fn (): string => insertFollow($task, $user)))
        ->toThrow(QueryException::class);

    expect(DB::table('task_followers')->count())->toBe(1);
});

it('lets two people follow the same task', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();

    insertFollow($task, memberOf($workspace));
    insertFollow($task, memberOf($workspace));

    expect(DB::table('task_followers')->count())->toBe(2);
});

it('lets one person follow two tasks', function (): void {
    $workspace = Workspace::factory()->create();
    $user = memberOf($workspace);

    insertFollow(Task::factory()->in($workspace)->create(), $user);
    insertFollow(Task::factory()->in($workspace)->create(), $user);

    expect(DB::table('task_followers')->count())->toBe(2);
});

it('removes the follows with the task', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    insertFollow($task, memberOf($workspace));

    $task->forceDelete();

    expect(DB::table('task_followers')->count())->toBe(0);
});

it('removes the follows with the account', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $user = memberOf($workspace);
    insertFollow($task, $user);

    $user->delete();

    expect(DB::table('task_followers')->count())->toBe(0)
        ->and(Task::query()->whereKey($task->id)->exists())->toBeTrue();
});

it('carries no workspace of its own', function (): void {
    expect(Schema::hasColumn('task_followers', 'workspace_id'))->toBeFalse();
});

it('records when somebody started following, and nothing else', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    insertFollow($task, memberOf($workspace));

    $row = DB::table('task_followers')->first();

    expect($row?->created_at)->not->toBeNull()
        ->and(Schema::hasColumn('task_followers', 'updated_at'))->toBeFalse();
});
