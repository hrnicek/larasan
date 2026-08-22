<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * An index that exists and an index the planner uses are two different claims. These tests
 * make the second one, against a table large enough that a sequential scan would be the
 * cheaper plan if the index did not fit the read.
 *
 * @return array{project: string, section: string, task: string}
 */
function seedPlacementBoard(int $projects = 20, int $sections = 5, int $perSection = 30): array
{
    $workspace = Workspace::factory()->create();
    $now = now();

    $projectRows = [];
    $sectionRows = [];
    $taskRows = [];
    $placementRows = [];

    $queried = null;

    for ($p = 0; $p < $projects; $p++) {
        $projectId = (string) Str::uuid7();

        $projectRows[] = [
            'id' => $projectId,
            'workspace_id' => $workspace->id,
            'name' => "Project {$p}",
            'slug' => "project-{$p}",
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // The last bucket of every project is the ungrouped one, which is a read of its own.
        for ($s = 0; $s <= $sections; $s++) {
            $sectionId = $s === $sections ? null : (string) Str::uuid7();

            if ($sectionId !== null) {
                $sectionRows[] = [
                    'id' => $sectionId,
                    'project_id' => $projectId,
                    'name' => "Section {$s}",
                    'color' => null,
                    'position' => ($s + 1) * 65536,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            for ($t = 0; $t < $perSection; $t++) {
                $taskId = (string) Str::uuid7();

                $taskRows[] = [
                    'id' => $taskId,
                    'workspace_id' => $workspace->id,
                    'title' => "Task {$p}-{$s}-{$t}",
                    'priority' => TaskPriority::Medium->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $placementRows[] = [
                    'id' => (string) Str::uuid7(),
                    'task_id' => $taskId,
                    'project_id' => $projectId,
                    'section_id' => $sectionId,
                    'position' => ($t + 1) * 65536,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($p === 0 && $s === 0 && $t === 0) {
                    $queried = ['project' => $projectId, 'section' => (string) $sectionId, 'task' => $taskId];
                }
            }
        }
    }

    DB::table('projects')->insert($projectRows);
    DB::table('sections')->insert($sectionRows);

    foreach (array_chunk($taskRows, 500) as $chunk) {
        DB::table('tasks')->insert($chunk);
    }

    foreach (array_chunk($placementRows, 500) as $chunk) {
        DB::table('task_project_memberships')->insert($chunk);
    }

    // Without statistics the planner works from defaults and its choice proves nothing.
    DB::statement('ANALYZE task_project_memberships');

    /** @var array{project: string, section: string, task: string} $queried */
    return $queried;
}

/**
 * @return array{nodes: list<string>, indexes: list<string>, sequential: list<string>}
 */
function planOf(Builder $query): array
{
    $rows = DB::select('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings());

    /** @var string $json */
    $json = ((array) $rows[0])['QUERY PLAN'];

    /** @var array<int, array{Plan: array<string, mixed>}> $decoded */
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    $nodes = [];
    $indexes = [];
    $sequential = [];

    $walk = function (array $plan) use (&$walk, &$nodes, &$indexes, &$sequential): void {
        $node = (string) $plan['Node Type'];
        $nodes[] = $node;

        if (isset($plan['Index Name'])) {
            $indexes[] = (string) $plan['Index Name'];
        }

        if ($node === 'Seq Scan') {
            $sequential[] = (string) ($plan['Relation Name'] ?? 'unknown');
        }

        /** @var array<int, array<string, mixed>> $children */
        $children = $plan['Plans'] ?? [];

        foreach ($children as $child) {
            $walk($child);
        }
    };

    $walk($decoded[0]['Plan']);

    return ['nodes' => $nodes, 'indexes' => $indexes, 'sequential' => $sequential];
}

function placementIndexDefinition(string $name): ?string
{
    /** @var object{indexdef: string}|null $row */
    $row = DB::table('pg_indexes')
        ->where('tablename', 'task_project_memberships')
        ->where('indexname', $name)
        ->first();

    return $row?->indexdef;
}

it('carries exactly the indexes the reads need and no prefix of another', function (): void {
    $names = collect(Schema::getIndexes('task_project_memberships'))->pluck('name')->sort()->values();

    /*
     * Pinned as a set, because the cost of an index is paid on every write. A plain
     * `INDEX(task_id)` was here and is not: it is a prefix of the unique index below, and
     * the lookup it existed for plans identically without it.
     */
    expect($names->all())->toBe([
        'task_project_memberships_pkey',
        'task_project_memberships_project_id_section_id_position_index',
        'task_project_memberships_slot_unique',
        'task_project_memberships_task_id_project_id_unique',
        'task_project_memberships_ungrouped_slot_unique',
    ]);
});

it('orders the composite index the way the board reads it', function (): void {
    $index = collect(Schema::getIndexes('task_project_memberships'))
        ->firstWhere('name', 'task_project_memberships_project_id_section_id_position_index');

    // Order, not membership: a leading `position` would index the same three columns and
    // serve none of the reads below, because every one of them filters on the project first.
    expect($index)->not->toBeNull()
        ->and($index['columns'])->toBe(['project_id', 'section_id', 'position']);
});

it('guards both slot buckets with partial unique indexes', function (): void {
    $grouped = placementIndexDefinition('task_project_memberships_slot_unique');
    $ungrouped = placementIndexDefinition('task_project_memberships_ungrouped_slot_unique');

    expect($grouped)->toContain('CREATE UNIQUE INDEX')
        ->and($grouped)->toContain('(project_id, section_id, "position")')
        ->and($grouped)->toContain('WHERE (section_id IS NOT NULL)')
        ->and($ungrouped)->toContain('CREATE UNIQUE INDEX')
        ->and($ungrouped)->toContain('(project_id, "position")')
        ->and($ungrouped)->toContain('WHERE (section_id IS NULL)');
});

it('plans a board column read as an ordered index scan', function (): void {
    ['project' => $project, 'section' => $section] = seedPlacementBoard();

    $plan = planOf(
        DB::table('task_project_memberships')
            ->where('project_id', $project)
            ->where('section_id', $section)
            ->orderBy('position')
    );

    /*
     * The slot guard is also the read index for a column: it leads with the same three
     * columns and covers fewer rows, so the planner prefers it to the composite index.
     */
    expect($plan['indexes'])->toContain('task_project_memberships_slot_unique')
        // The ordering comes out of the index. A `Sort` node would mean the column order
        // was wrong and every board read paid for it.
        ->and($plan['nodes'])->not->toContain('Sort')
        ->and($plan['sequential'])->toBe([]);
});

it('plans the ungrouped bucket read as an ordered index scan', function (): void {
    ['project' => $project] = seedPlacementBoard();

    $plan = planOf(
        DB::table('task_project_memberships')
            ->where('project_id', $project)
            ->whereNull('section_id')
            ->orderBy('position')
    );

    // The bucket the unique constraint needed a partial index for reads through that same
    // partial index, ordered, without a sort.
    expect($plan['indexes'])->toContain('task_project_memberships_ungrouped_slot_unique')
        ->and($plan['nodes'])->not->toContain('Sort')
        ->and($plan['sequential'])->toBe([]);
});

it('plans a whole project read on the composite index', function (): void {
    ['project' => $project] = seedPlacementBoard();

    $plan = planOf(DB::table('task_project_memberships')->where('project_id', $project));

    /*
     * The read that justifies the composite index existing next to the two partial ones: it
     * names no section, so neither partial index applies — a partial index can only serve a
     * query that implies its predicate. Dropping the composite index makes this a sequential
     * scan, which is how it was verified.
     */
    expect($plan['indexes'])->toContain('task_project_memberships_project_id_section_id_position_index')
        ->and($plan['sequential'])->toBe([]);
});

it('plans the board read joined to its columns without scanning the placements', function (): void {
    ['project' => $project] = seedPlacementBoard();

    /*
     * The real board query orders columns by `sections.position`, not by `section_id`, so
     * the join makes a sort unavoidable. What the index has to do here is find the project's
     * placements without reading the table.
     */
    $plan = planOf(
        DB::table('task_project_memberships as m')
            ->leftJoin('sections as s', 's.id', '=', 'm.section_id')
            ->where('m.project_id', $project)
            ->orderBy('s.position')
            ->orderBy('m.position')
    );

    expect($plan['indexes'])->toContain('task_project_memberships_project_id_section_id_position_index')
        ->and($plan['sequential'])->not->toContain('task_project_memberships');
});

it('plans where does this task appear on the unique index', function (): void {
    ['task' => $task] = seedPlacementBoard();

    $plan = planOf(DB::table('task_project_memberships')->where('task_id', $task));

    // `UNIQUE(task_id, project_id)` leads with `task_id`, so it is this lookup's index as
    // well as the constraint — and the index PostgreSQL needs for the cascade when a task
    // is deleted, since it does not index the referencing side of a foreign key itself.
    expect($plan['indexes'])->toContain('task_project_memberships_task_id_project_id_unique')
        ->and($plan['sequential'])->toBe([]);
});
