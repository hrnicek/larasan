<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $overrides
 */
function insertPlacement(Task $task, Project $project, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('task_project_memberships')->insert([
        'id' => $id,
        'task_id' => $task->id,
        'project_id' => $project->id,
        'section_id' => null,
        'position' => 65536,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);

    return $id;
}

/**
 * @return array{Task, Project, Section}
 */
function placementFixtures(): array
{
    $workspace = Workspace::factory()->create();
    $project = Project::factory()->in($workspace)->create();

    return [
        Task::factory()->in($workspace)->create(),
        $project,
        Section::factory()->in($project)->create(),
    ];
}

it('lets a task appear in a project exactly once', function (): void {
    [$task, $project] = placementFixtures();
    insertPlacement($task, $project);

    // Savepoint: PostgreSQL aborts the whole transaction on a failed statement.
    expect(fn (): string => DB::transaction(fn (): string => insertPlacement($task, $project, ['position' => 131072])))
        ->toThrow(QueryException::class);

    expect(DB::table('task_project_memberships')->count())->toBe(1);
});

it('lets the same task appear in two projects', function (): void {
    [$task, $project] = placementFixtures();
    $other = Project::factory()->in($task->workspace)->create();

    insertPlacement($task, $project);
    insertPlacement($task, $other);

    expect(DB::table('task_project_memberships')->count())->toBe(2)
        ->and(Task::query()->count())->toBe(1);
});

it('refuses two placements in one slot of a section', function (): void {
    [$task, $project, $section] = placementFixtures();
    $second = Task::factory()->in($task->workspace)->create();

    insertPlacement($task, $project, ['section_id' => $section->id]);

    expect(fn (): string => DB::transaction(fn (): string => insertPlacement($second, $project, ['section_id' => $section->id])))
        ->toThrow(QueryException::class);
});

it('refuses two placements in one slot of the ungrouped bucket', function (): void {
    [$task, $project] = placementFixtures();
    $second = Task::factory()->in($task->workspace)->create();

    insertPlacement($task, $project);

    // PostgreSQL treats NULLs as distinct, so the ungrouped bucket needs its own partial unique index.
    expect(fn (): string => DB::transaction(fn (): string => insertPlacement($second, $project)))
        ->toThrow(QueryException::class);
});

it('lets two projects use the same slot', function (): void {
    [$task, $project] = placementFixtures();
    $other = Project::factory()->in($task->workspace)->create();
    $second = Task::factory()->in($task->workspace)->create();

    insertPlacement($task, $project);
    insertPlacement($second, $other);

    expect(DB::table('task_project_memberships')->where('position', 65536)->count())->toBe(2);
});

it('refuses to delete a section that still holds placements', function (): void {
    [$task, $project, $section] = placementFixtures();
    $id = insertPlacement($task, $project, ['section_id' => $section->id]);

    // Nulling the section would keep the position and can collide in the ungrouped bucket; DeleteSection moves cards first.
    expect(fn (): int => DB::transaction(fn (): int => DB::table('sections')->where('id', $section->id)->delete()))
        ->toThrow(QueryException::class, 'task_project_memberships_section_id_foreign');

    expect(DB::table('task_project_memberships')->where('id', $id)->value('section_id'))->toBe($section->id);
});

it('deletes an empty section', function (): void {
    [, , $section] = placementFixtures();

    DB::table('sections')->where('id', $section->id)->delete();

    expect(DB::table('sections')->count())->toBe(0);
});

it('removes sections and their placements together with the project', function (): void {
    [$task, $project, $section] = placementFixtures();
    insertPlacement($task, $project, ['section_id' => $section->id]);

    DB::table('projects')->where('id', $project->id)->delete();

    expect(DB::table('sections')->count())->toBe(0)
        ->and(DB::table('task_project_memberships')->count())->toBe(0);
});

it('removes sections and their placements together with the workspace', function (): void {
    [$task, $project, $section] = placementFixtures();
    insertPlacement($task, $project, ['section_id' => $section->id]);

    DB::table('workspaces')->where('id', $project->workspace_id)->delete();

    expect(DB::table('sections')->count())->toBe(0)
        ->and(DB::table('task_project_memberships')->count())->toBe(0);
});

it('removes placements with the project and keeps the task', function (): void {
    [$task, $project] = placementFixtures();
    insertPlacement($task, $project);

    $project->forceDelete();

    expect(DB::table('task_project_memberships')->count())->toBe(0)
        ->and(Task::query()->whereKey($task->id)->exists())->toBeTrue();
});

it('removes placements with the task', function (): void {
    [$task, $project] = placementFixtures();
    insertPlacement($task, $project);

    $task->forceDelete();

    expect(DB::table('task_project_memberships')->count())->toBe(0)
        ->and(Project::query()->whereKey($project->id)->exists())->toBeTrue();
});

it('indexes the ordered column read and the reverse lookup', function (): void {
    $indexes = collect(Schema::getIndexes('task_project_memberships'))->pluck('columns');

    expect($indexes)->toContain(['project_id', 'section_id', 'position'])
        ->and($indexes)->toContain(['task_id', 'project_id'])
        ->and($indexes)->toContain(['section_id', 'position']);
});
