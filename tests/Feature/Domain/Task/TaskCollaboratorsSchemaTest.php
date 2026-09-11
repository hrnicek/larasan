<?php

declare(strict_types=1);

use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskCollaborator;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Asserted through raw inserts, so what is proven is the database's behaviour rather than a
 * model's.
 */
function insertCollaboration(Task $task, User $user): string
{
    $id = (string) Str::uuid7();

    DB::table('task_collaborators')->insert([
        'id' => $id,
        'task_id' => $task->id,
        'user_id' => $user->id,
        'created_at' => now(),
    ]);

    return $id;
}

it('puts somebody on a task once', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $user = memberOf($workspace);

    insertCollaboration($task, $user);

    expect(fn (): string => DB::transaction(fn (): string => insertCollaboration($task, $user)))
        ->toThrow(QueryException::class);

    expect(DB::table('task_collaborators')->count())->toBe(1);
});

it('lets several people work on one task, and one person on several', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $user = memberOf($workspace);

    insertCollaboration($task, $user);
    insertCollaboration($task, memberOf($workspace));
    insertCollaboration(Task::factory()->in($workspace)->create(), $user);

    expect(DB::table('task_collaborators')->count())->toBe(3);
});

it('removes the collaborations with the task', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    insertCollaboration($task, memberOf($workspace));

    $task->forceDelete();

    expect(DB::table('task_collaborators')->count())->toBe(0);
});

it('removes the collaborations with the account and keeps the task', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $user = memberOf($workspace);
    insertCollaboration($task, $user);

    $user->delete();

    expect(DB::table('task_collaborators')->count())->toBe(0)
        ->and(Task::query()->whereKey($task->id)->exists())->toBeTrue();
});

it('carries no workspace and no updated_at of its own', function (): void {
    expect(Schema::hasColumn('task_collaborators', 'workspace_id'))->toBeFalse()
        ->and(Schema::hasColumn('task_collaborators', 'updated_at'))->toBeFalse();
});

it('reads the people on a task by name, beside the rows', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $zoe = memberOf($workspace, user: User::factory()->create(['name' => 'Zoe Zeta']));
    $adam = memberOf($workspace, user: User::factory()->create(['name' => 'Adam Alpha']));

    TaskCollaborator::factory()->on($task, $zoe)->create();
    TaskCollaborator::factory()->on($task, $adam)->create();

    expect($task->collaborators()->pluck('users.id')->all())->toBe([$adam->id, $zoe->id])
        ->and($task->collaborations()->count())->toBe(2);
});
