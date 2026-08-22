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
 * Asserted through raw inserts, before a model exists, so what is proven is the database's
 * behaviour rather than a model's.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertComment(Workspace $workspace, Task $task, ?User $author = null, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('comments')->insert([
        'id' => $id,
        'workspace_id' => $workspace->id,
        'commentable_type' => 'task',
        'commentable_id' => $task->id,
        'author_id' => $author?->id,
        'body' => 'Looks right to me',
        'edited_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);

    return $id;
}

it('carries its own workspace rather than joining for one', function (): void {
    /*
     * The exception ADR-0005 allows. A comment is polymorphic, so there is no single aggregate
     * to join through, and a scoped read would otherwise need a union over every commentable
     * table.
     */
    expect(Schema::hasColumn('comments', 'workspace_id'))->toBeTrue();
});

it('goes with the workspace', function (): void {
    $workspace = Workspace::factory()->create();
    insertComment($workspace, Task::factory()->in($workspace)->create(), memberOf($workspace));

    $workspace->delete();

    expect(DB::table('comments')->count())->toBe(0);
});

it('survives the account that wrote it', function (): void {
    $workspace = Workspace::factory()->create();
    $author = memberOf($workspace);
    $id = insertComment($workspace, Task::factory()->in($workspace)->create(), $author);

    $author->delete();

    // A comment outlives its author, the way `tasks.completed_by` does: deleting a person must
    // not rewrite a conversation other people took part in.
    $comment = DB::table('comments')->where('id', $id)->first();

    expect($comment)->not->toBeNull()
        ->and($comment?->author_id)->toBeNull()
        ->and($comment?->body)->toBe('Looks right to me');
});

it('refuses a comment with no workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();

    expect(fn (): string => DB::transaction(fn (): string => insertComment($workspace, $task, null, ['workspace_id' => null])))
        ->toThrow(QueryException::class);
});

it('refuses a comment with no body', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();

    expect(fn (): string => DB::transaction(fn (): string => insertComment($workspace, $task, null, ['body' => null])))
        ->toThrow(QueryException::class);
});

it('starts unedited and undeleted', function (): void {
    $workspace = Workspace::factory()->create();
    $id = insertComment($workspace, Task::factory()->in($workspace)->create());

    $comment = DB::table('comments')->where('id', $id)->first();

    expect($comment?->edited_at)->toBeNull()
        ->and($comment?->deleted_at)->toBeNull();
});

it('indexes the feed read, in the feed order, and nothing that is a prefix of it', function (): void {
    $indexes = collect(Schema::getIndexes('comments'))->pluck('columns');

    /*
     * One composite index, not two. `uuidMorphs()` would have added its own
     * `(commentable_type, commentable_id)` — a prefix of this one, paid for on every write and
     * never chosen, which is the shape TASK-070-002 found on the placement table.
     */
    expect($indexes)->toContain(['commentable_type', 'commentable_id', 'created_at'])
        ->and($indexes)->not->toContain(['commentable_type', 'commentable_id'])
        ->and($indexes)->toContain(['author_id']);
});

it('plans a task s comments as an ordered index scan', function (): void {
    $workspace = Workspace::factory()->create();
    $author = memberOf($workspace);

    /*
     * Three thousand comments across twenty tasks, `ANALYZE`d, so the planner is choosing from
     * statistics and a sequential scan is a plan it could reasonably prefer. An index the
     * planner ignores is not an index the query has (TASK-070-002).
     */
    $rows = [];

    foreach (range(1, 20) as $ignored) {
        $task = Task::factory()->in($workspace)->create();

        foreach (range(1, 150) as $index) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'workspace_id' => $workspace->id,
                'commentable_type' => 'task',
                'commentable_id' => $task->id,
                'author_id' => $author->id,
                'body' => "Comment {$index}",
                'edited_at' => null,
                'created_at' => now()->addSeconds($index),
                'updated_at' => now(),
            ];
        }
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('comments')->insert($chunk);
    }

    DB::statement('ANALYZE comments');

    $subject = (string) $rows[0]['commentable_id'];

    // The feed's real read: one page, in order. Without the limit the planner reads 150 of
    // 3000 rows and prefers a bitmap scan plus a sort, which is the right plan for that shape
    // and not the one a paginated feed issues.
    $query = DB::table('comments')
        ->where('commentable_type', 'task')
        ->where('commentable_id', $subject)
        ->orderBy('created_at')
        ->limit(50);

    $explained = DB::select('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings());

    /** @var string $json */
    $json = ((array) $explained[0])['QUERY PLAN'];
    $plan = (string) json_encode(json_decode($json, true, 512, JSON_THROW_ON_ERROR));

    // The ordering falls out of the index as well: a `Sort` node would mean the column order
    // was wrong and every feed read paid for it.
    expect($plan)->toContain('comments_commentable_type_commentable_id_created_at_index')
        ->and($plan)->not->toContain('Seq Scan')
        ->and($plan)->not->toContain('"Node Type":"Sort"');
});
