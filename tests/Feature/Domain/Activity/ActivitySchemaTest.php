<?php

declare(strict_types=1);

use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $overrides
 */
function insertActivity(Workspace $workspace, Task $task, ?User $actor = null, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('activities')->insert([
        'id' => $id,
        'workspace_id' => $workspace->id,
        'subject_type' => 'task',
        'subject_id' => $task->id,
        'actor_id' => $actor?->id,
        'type' => 'task.completed',
        'properties' => json_encode(['from' => null, 'to' => 'done'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        ...$overrides,
    ]);

    return $id;
}

it('records what happened and never that it changed', function (): void {
    expect(Schema::hasColumn('activities', 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn('activities', 'updated_at'))->toBeFalse();
});

it('goes with the workspace', function (): void {
    $workspace = Workspace::factory()->create();
    insertActivity($workspace, Task::factory()->in($workspace)->create(), memberOf($workspace));

    $workspace->delete();

    expect(DB::table('activities')->count())->toBe(0);
});

it('survives the account that caused it', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $id = insertActivity($workspace, Task::factory()->in($workspace)->create(), $actor);

    $actor->delete();

    $activity = DB::table('activities')->where('id', $id)->first();

    expect($activity)->not->toBeNull()
        ->and($activity?->actor_id)->toBeNull()
        ->and($activity?->type)->toBe('task.completed');
});

it('refuses an activity missing anything it needs', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();

    foreach (['workspace_id', 'subject_type', 'subject_id', 'type', 'properties', 'created_at'] as $column) {
        expect(fn (): string => DB::transaction(fn (): string => insertActivity($workspace, $task, null, [$column => null])))
            ->toThrow(QueryException::class);
    }
});

it('keeps whatever that kind of event needed', function (): void {
    $workspace = Workspace::factory()->create();
    $id = insertActivity($workspace, Task::factory()->in($workspace)->create(), null, [
        'type' => 'task.renamed',
        'properties' => json_encode(['from' => 'Old', 'to' => 'New'], JSON_THROW_ON_ERROR),
    ]);

    $properties = json_decode((string) DB::table('activities')->where('id', $id)->value('properties'), true, 512, JSON_THROW_ON_ERROR);

    expect($properties)->toBe(['from' => 'Old', 'to' => 'New']);
});

it('indexes the feed read, in the feed order, and both cascades', function (): void {
    $indexes = collect(Schema::getIndexes('activities'))->pluck('columns');

    expect($indexes)->toContain(['subject_type', 'subject_id', 'created_at'])
        // uuidMorphs() would add this redundant prefix index.
        ->and($indexes)->not->toContain(['subject_type', 'subject_id'])
        // PostgreSQL does not index the referencing side of a foreign key.
        ->and($indexes)->toContain(['actor_id'])
        ->and($indexes)->toContain(['workspace_id']);
});

it("plans a task's history as an ordered index scan", function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    // Enough analysed rows that a sequential scan is a plan the planner could reasonably choose.
    $rows = [];

    foreach (range(1, 20) as $ignored) {
        $task = Task::factory()->in($workspace)->create();

        foreach (range(1, 150) as $index) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'workspace_id' => $workspace->id,
                'subject_type' => 'task',
                'subject_id' => $task->id,
                'actor_id' => $actor->id,
                'type' => 'task.renamed',
                'properties' => json_encode(['to' => "Title {$index}"], JSON_THROW_ON_ERROR),
                'created_at' => now()->addSeconds($index),
            ];
        }
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('activities')->insert($chunk);
    }

    DB::statement('ANALYZE activities');

    $query = DB::table('activities')
        ->where('subject_type', 'task')
        ->where('subject_id', (string) $rows[0]['subject_id'])
        ->orderBy('created_at')
        ->limit(50);

    $explained = DB::select('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings());

    /** @var string $json */
    $json = ((array) $explained[0])['QUERY PLAN'];
    $plan = (string) json_encode(json_decode($json, true, 512, JSON_THROW_ON_ERROR));

    expect($plan)->toContain('activities_subject_type_subject_id_created_at_index')
        ->and($plan)->not->toContain('Seq Scan')
        ->and($plan)->not->toContain('"Node Type":"Sort"');
});
