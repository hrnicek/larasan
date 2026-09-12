<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @return list<array{table: string, name: string, columns: string, partial: bool}>
 */
function applicationIndexes(): array
{
    /** @var list<object{table: string, name: string, definition: string, partial: bool}> $rows */
    $rows = DB::select(<<<'SQL'
        select
            t.relname as "table",
            i.relname as "name",
            pg_get_indexdef(i.oid) as "definition",
            ix.indpred is not null as "partial"
        from pg_index ix
        join pg_class i on i.oid = ix.indexrelid
        join pg_class t on t.oid = ix.indrelid
        join pg_namespace n on n.oid = t.relnamespace
        where n.nspname = 'public'
          and t.relname not in (
            'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
            'sessions', 'password_reset_tokens'
          )
        order by t.relname, i.relname
    SQL);

    return array_map(static fn (object $row): array => [
        'table' => $row->table,
        'name' => $row->name,
        'columns' => (string) Str::of($row->definition)->after('(')->before(')'),
        'partial' => (bool) $row->partial,
    ], $rows);
}

/**
 * @param  list<mixed>  $bindings
 */
function explainText(string $sql, array $bindings = []): string
{
    /** @var list<object{'QUERY PLAN': string}> $rows */
    $rows = DB::select('EXPLAIN (ANALYZE, FORMAT TEXT) '.$sql, $bindings);

    return implode("\n", array_map(static fn (object $row): string => ((array) $row)['QUERY PLAN'], $rows));
}

/**
 * Partial indexes are exempt: they cover only part of the table, so a full index over the same
 * columns is not redundant.
 *
 * @return list<string>
 */
function prefixIndexes(): array
{
    $byTable = collect(applicationIndexes())
        ->reject(fn (array $index): bool => $index['partial'])
        ->groupBy('table');

    $redundant = [];

    foreach ($byTable as $table => $indexes) {
        foreach ($indexes as $candidate) {
            foreach ($indexes as $other) {
                if ($candidate['name'] === $other['name'] || $candidate['columns'] === $other['columns']) {
                    continue;
                }

                if (str_starts_with($other['columns'].',', $candidate['columns'].',')) {
                    $redundant[] = "{$table}.{$candidate['name']} ({$candidate['columns']}) is a prefix of {$other['name']}";
                }
            }
        }
    }

    return $redundant;
}

it('carries no index that is a prefix of another on the same table', function (): void {
    expect(prefixIndexes())->toBe([]);
})->with([
    'the cost of an index is paid on every write, and a prefix of another index is paid for
    twice',
]);

it('notices when an index is a prefix of another', function (): void {
    // PostgreSQL DDL is transactional, so the probe index is rolled back with the test.
    DB::statement('CREATE INDEX projects_prefix_probe ON projects (workspace_id)');

    $redundant = prefixIndexes();

    expect($redundant)->toContain(
        'projects.projects_prefix_probe (workspace_id) is a prefix of projects_workspace_id_archived_at_index',
    );
})->with([
    'a schema check that cannot fail is a schema check nobody should trust',
]);

/**
 * A partial index counts only when its predicate is "column IS NOT NULL", which the key lookup always implies.
 *
 * @return list<string>
 */
function unindexedForeignKeys(): array
{
    /** @var list<object{table: string, column: string}> $rows */
    $rows = DB::select(<<<'SQL'
        select c.conrelid::regclass::text as "table", a.attname as "column"
        from pg_constraint c
        join pg_attribute a on a.attrelid = c.conrelid and a.attnum = c.conkey[1]
        join pg_class t on t.oid = c.conrelid
        join pg_namespace n on n.oid = t.relnamespace
        where c.contype = 'f'
          and n.nspname = 'public'
          and not exists (
            select 1
            from pg_index i
            where i.indrelid = c.conrelid
              and i.indkey[0] = c.conkey[1]
              and (
                i.indpred is null
                or pg_get_expr(i.indpred, i.indrelid) = '(' || quote_ident(a.attname) || ' IS NOT NULL)'
              )
          )
        order by 1, 2
    SQL);

    return array_map(static fn (object $row): string => "{$row->table}.{$row->column}", $rows);
}

it('indexes the referencing side of every foreign key', function (): void {
    expect(unindexedForeignKeys())->toBe([]);
});

it('notices a foreign key without an index', function (): void {
    DB::statement('DROP INDEX tasks_assignee_id_index');
    DB::statement('DROP INDEX task_project_memberships_section_id_position_index');
    DB::statement('CREATE INDEX task_project_memberships_section_probe ON task_project_memberships (section_id) WHERE position > 0');

    expect(unindexedForeignKeys())->toBe(['task_project_memberships.section_id', 'tasks.assignee_id']);
});

it('plans my tasks on an index that finds the assignee', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    seedTaskRows($workspace, $actor, 5_000);

    DB::statement('ANALYZE tasks');

    $plan = explainText(
        'select id from tasks where workspace_id = ? and assignee_id = ? and completed_at is null limit 50',
        [$workspace->id, $actor->id],
    );

    // Both the My Tasks composite and the foreign key index on assignee_id answer this; the planner may take either.
    expect($plan)->not->toContain('Seq Scan')
        ->and($plan)->toMatch('/Index Cond: .*assignee_id = /');
});

it('plans the inbox on the workspace index rather than the frameworks own', function (): void {
    $workspace = Workspace::factory()->create();
    $reader = memberOf($workspace);

    seedNotificationRows($workspace, $reader, 5_000);

    DB::statement('ANALYZE notifications');

    $plan = explainText(
        "select id from notifications where workspace_id = ? and notifiable_type = 'user' and notifiable_id = ? and read_at is null limit 25",
        [$workspace->id, $reader->id],
    );

    expect($plan)->toContain('notifications_workspace_id_notifiable_id_read_at_index');
})->with([
    'the Inbox is per workspace, and the framework index leads with notifiable_type — which
    every row in this application shares',
]);

it('plans a comment thread on its own index', function (): void {
    $workspace = Workspace::factory()->create();
    $author = memberOf($workspace);

    $thread = (string) Str::uuid7();
    seedCommentRows($workspace, $author, $thread, 3_000);

    DB::statement('ANALYZE comments');

    $plan = explainText(
        "select id from comments where commentable_type = 'task' and commentable_id = ? order by created_at limit 50",
        [$thread],
    );

    expect($plan)->toContain('comments_commentable_type_commentable_id_created_at_index');
});

/**
 * Bulk inserts: the table must be large enough for a sequential scan to lose, which factories cannot build quickly.
 */
function seedTaskRows(Workspace $workspace, User $assignee, int $count): void
{
    $now = now();
    $rows = [];

    foreach (range(1, $count) as $index) {
        $rows[] = [
            'id' => (string) Str::uuid7(),
            'workspace_id' => $workspace->id,
            'title' => "Task {$index}",
            'priority' => TaskPriority::Medium->value,
            // 2% selectivity, so the planner prefers the index.
            'assignee_id' => $index % 50 === 0 ? $assignee->id : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($rows, 1_000) as $chunk) {
        DB::table('tasks')->insert($chunk);
    }
}

function seedNotificationRows(Workspace $workspace, User $reader, int $count): void
{
    $now = now();
    $rows = [];
    $other = User::factory()->create();

    foreach (range(1, $count) as $index) {
        $rows[] = [
            'id' => (string) Str::uuid7(),
            'workspace_id' => $workspace->id,
            'type' => 'App\\Domain\\Notification\\Notifications\\TaskAssignedNotification',
            'notifiable_type' => 'user',
            'notifiable_id' => $index % 50 === 0 ? $reader->id : $other->id,
            'data' => json_encode(['task_id' => (string) Str::uuid7(), 'assigned_by_id' => $other->id]),
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($rows, 1_000) as $chunk) {
        DB::table('notifications')->insert($chunk);
    }
}

function seedCommentRows(Workspace $workspace, User $author, string $thread, int $count): void
{
    $now = now();
    $rows = [];

    foreach (range(1, $count) as $index) {
        $rows[] = [
            'id' => (string) Str::uuid7(),
            'workspace_id' => $workspace->id,
            'commentable_type' => 'task',
            'commentable_id' => $index % 50 === 0 ? $thread : (string) Str::uuid7(),
            'author_id' => $author->id,
            'body' => "Comment {$index}",
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($rows, 1_000) as $chunk) {
        DB::table('comments')->insert($chunk);
    }
}
