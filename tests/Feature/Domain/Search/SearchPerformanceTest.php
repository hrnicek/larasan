<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Search\Queries\SearchTasksQuery;
use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    // Only the term is filtered, because in a single workspace workspace_id matches every row.
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

    // The OR between two subplans hides selectivity, so at this size the planner rightly prefers a scan.
    // Sequential scans are disabled to prove the GIN index is still usable.
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

    // A loose bound that catches an order-of-magnitude regression, not a benchmark.
    expect($result['tasks'])->not->toBeEmpty()
        ->and($elapsed)->toBeLessThan(500);
});
