<?php

declare(strict_types=1);

use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\CustomFieldOption;
use App\Domain\Page\Models\Page;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\CustomFieldType;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Models\Task;

it('builds several sections in one project', function (): void {
    $project = Project::factory()->create();
    Section::factory()->in($project)->at(SparsePosition::GAP)->create();

    $sections = Section::factory()->in($project)->count(3)->create();

    expect($sections->pluck('position')->all())->toBe([2 * SparsePosition::GAP, 3 * SparsePosition::GAP, 4 * SparsePosition::GAP]);
});

it('builds several placements in one bucket of a project', function (): void {
    $project = Project::factory()->create();

    $placements = TaskProjectMembership::factory()
        ->state(fn (): array => [
            'task_id' => Task::factory()->in($project->workspace)->create()->id,
            'project_id' => $project->id,
        ])
        ->count(3)
        ->create();

    expect($placements->pluck('position')->all())->toBe([SparsePosition::GAP, 2 * SparsePosition::GAP, 3 * SparsePosition::GAP]);
});

it('builds several placements in one section, with tasks from the section s workspace', function (): void {
    $section = Section::factory()->create();

    $placements = TaskProjectMembership::factory()->inSection($section)->count(3)->create();

    $workspaces = Task::query()->whereIn('id', $placements->pluck('task_id'))->pluck('workspace_id')->unique()->all();

    expect($placements->pluck('position')->all())->toBe([SparsePosition::GAP, 2 * SparsePosition::GAP, 3 * SparsePosition::GAP])
        ->and($workspaces)->toBe([$section->project->workspace_id]);
});

it('builds a project for a given task in that task s workspace', function (): void {
    $task = Task::factory()->create();

    $placement = TaskProjectMembership::factory()->create(['task_id' => $task->id]);

    expect($placement->project->workspace_id)->toBe($task->workspace_id);
});

it('builds several options of one field', function (): void {
    $field = CustomField::factory()->ofType(CustomFieldType::Select)->create();

    $options = CustomFieldOption::factory()->of($field)->count(3)->create();

    expect($options->pluck('position')->all())->toBe([1, 2, 3]);
});

it('builds several pages in one project without creating another project', function (): void {
    $project = Project::factory()->create();
    $parent = Page::factory()->in($project)->create();
    $projects = Project::query()->count();

    $roots = Page::factory()->in($project)->count(3)->create();
    $children = Page::factory()->under($parent)->count(3)->create();

    expect(Project::query()->count())->toBe($projects)
        ->and($roots->pluck('project_id')->unique()->all())->toBe([$project->id])
        ->and($children->pluck('parent_id')->unique()->all())->toBe([$parent->id]);
});
