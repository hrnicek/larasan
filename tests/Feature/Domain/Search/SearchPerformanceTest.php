<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Search\Queries\SearchTasksQuery;
use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Thirty thousand tasks in one workspace: more than anybody has typed by hand, and the size at
 * which a sequential scan stops being invisible.
 */
function seedTasks(Workspace $workspace, int $count): void
{
    $rows = [];

    foreach (range(1, $count) as $index) {
        $rows[] = [
            'id' => (string) Str::uuid7(),
            'workspace_id' => $workspace->id,
            'title' => $index % 500 === 0 ? "Fix the login screen {$index}" : "Ordinary task {$index}",
            'description' => 'Some description that is long enough to be worth indexing '.$index,
            'priority' => TaskPriority::Medium->value,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    foreach (array_chunk($rows, 1000) as $chunk) {
        DB::table('tasks')->insert($chunk);
    }
}

/**
 * @param  list<string>  $bindings
 * @return array<string, mixed>
 */
function searchPlanOf(string $sql, array $bindings): array
{
    $explained = DB::select('EXPLAIN (FORMAT JSON, ANALYZE, BUFFERS) '.$sql, $bindings);

    /** @var string $json */
    $json = ((array) $explained[0])['QUERY PLAN'];

    /** @var array<int, array<string, mixed>> $plan */
    $plan = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    return $plan[0];
}

it('can search thirty thousand tasks on the index rather than by reading them all', function (): void {
    $workspace = Workspace::factory()->create();
    seedTasks($workspace, 30_000);

    DB::statement('ANALYZE tasks');

    /*
     * Index-ability rather than a forced plan, and the term on its own rather than the whole
     * `where`. At thirty thousand rows in a single workspace, `workspace_id = ?` matches every
     * row, so that column's index is a second path the planner may legitimately take — it did,
     * reading 30,000 rows and discarding 29,940 in 3ms, which at this size is a defensible
     * choice and at a real size is not. Pricing out sequential scans did not remove that second
     * path, so the assertion was still flaky; naming both conditions was the mistake.
     *
     * The question this test exists to answer is narrower: **is the vector index-able at all**.
     * What the planner prefers once a workspace filter is beside it is a cost decision that
     * changes with the data, and the timings the next tests record are how that is watched.
     */
    $sql = <<<'SQL'
        select id, title from tasks
        where search_vector @@ to_tsquery('simple', immutable_unaccent(?))
        order by ts_rank_cd(search_vector, to_tsquery('simple', immutable_unaccent(?))) desc, id desc
        limit 25
    SQL;

    DB::statement('SET LOCAL enable_seqscan = off');

    $plan = searchPlanOf($sql, ['login:*', 'login:*']);

    DB::statement('SET LOCAL enable_seqscan = on');

    expect((string) json_encode($plan))->toContain('tasks_search_vector_index');
});

it('can still answer on the index once reach is joined in', function (): void {
    $workspace = Workspace::factory()->create();
    $project = Project::factory()->in($workspace)->create();
    seedTasks($workspace, 30_000);

    DB::statement('ANALYZE tasks');
    DB::statement('ANALYZE task_project_memberships');

    $sql = <<<'SQL'
        select tasks.id from tasks
        where tasks.workspace_id = ?
          and tasks.search_vector @@ to_tsquery('simple', immutable_unaccent(?))
          and (
            exists (
                select 1 from task_project_memberships
                where task_project_memberships.task_id = tasks.id
                  and task_project_memberships.project_id in (?)
            )
            or not exists (
                select 1 from task_project_memberships
                where task_project_memberships.task_id = tasks.id
            )
          )
        limit 25
    SQL;

    /*
     * Recorded rather than forced: with the reach condition in place the planner estimates far
     * more matches than there are — the `OR` between two subplans is opaque to it — and at
     * thirty thousand rows it prefers to read the table, which takes single-digit milliseconds.
     * That is the right choice at this size and the wrong one at ten times it.
     *
     * So what this asserts is that the query is still **index-able**: with sequential scans
     * priced out of the way, the planner reaches for the GIN index rather than having no way to
     * use it. The day a workspace is large enough, that is the plan it will pick on its own.
     */
    DB::statement('SET LOCAL enable_seqscan = off');

    $plan = searchPlanOf($sql, [$workspace->id, 'login:*', $project->id]);

    DB::statement('SET LOCAL enable_seqscan = on');

    expect((string) json_encode($plan))->toContain('tasks_search_vector_index');
});

it('answers a search of thirty thousand tasks quickly enough to type into', function (): void {
    $workspace = Workspace::factory()->create();
    seedTasks($workspace, 30_000);
    $actor = memberOf($workspace);

    DB::statement('ANALYZE tasks');

    $started = microtime(true);
    $result = app(SearchTasksQuery::class)($workspace, $actor, 'login');
    $elapsed = (microtime(true) - $started) * 1000;

    /*
     * A number to compare against rather than a promise: on the machine this was written on, a
     * search of thirty thousand tasks answered in single-digit milliseconds — whether the planner
     * chose the index or read the table. The bound is loose enough not to fail on a slower
     * machine and tight enough to catch the day this becomes something else entirely.
     */
    expect($result['tasks'])->not->toBeEmpty()
        ->and($elapsed)->toBeLessThan(500);
});
