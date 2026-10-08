<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Actions\DeleteSection;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/**
 * @return array{Section, Project, User, list<string>}
 */
function columnOf(int $cards): array
{
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Owner);
    $project = Project::factory()->in($workspace)->create();
    ProjectMembership::factory()->in($project)->forUser($actor)->withAccess(ProjectAccessLevel::Owner)->create();
    $section = Section::factory()->in($project)->create();

    $titles = [];

    foreach (range(1, $cards) as $index) {
        $task = Task::factory()->in($workspace)->create(['title' => "Card {$index}"]);
        $titles[] = $task->title;

        TaskProjectMembership::factory()->placing($task, $project)->create([
            'section_id' => $section->id,
            'position' => ($cards - $index + 1) * TaskProjectMembership::POSITION_GAP,
        ]);
    }

    return [$section, $project, $actor, array_reverse($titles)];
}

it('costs the same whether the column holds ten cards or forty', function (): void {
    $counts = [];

    foreach ([10, 40] as $cards) {
        [$section, , $actor] = columnOf($cards);

        $counts[] = count(queriesWhile(function () use ($section, $actor): void {
            app(DeleteSection::class)->handle($section, $actor);
        }));
    }

    [$small, $large] = $counts;

    expect($large)->toBe($small, "deleting a column of forty made {$large} queries and one of ten made {$small}");
});

it('keeps the cards in the order the column had them', function (): void {
    [$section, $project, $actor, $titles] = columnOf(5);

    app(DeleteSection::class)->handle($section, $actor);

    $order = $project->placements()
        ->whereNull('section_id')
        ->with('task')
        ->orderBy('position')
        ->get()
        ->map(fn (TaskProjectMembership $placement): string => (string) $placement->task->title)
        ->all();

    expect($order)->toBe($titles);
});

it('appends them after whatever the ungrouped bucket already held', function (): void {
    [$section, $project, $actor, $titles] = columnOf(3);

    $loose = Task::factory()->in($project->workspace)->create(['title' => 'Already loose']);
    TaskProjectMembership::factory()->placing($loose, $project)->create([
        'section_id' => null,
        'position' => 7 * TaskProjectMembership::POSITION_GAP,
    ]);

    app(DeleteSection::class)->handle($section, $actor);

    $order = $project->placements()
        ->whereNull('section_id')
        ->with('task')
        ->orderBy('position')
        ->get()
        ->map(fn (TaskProjectMembership $placement): string => (string) $placement->task->title)
        ->all();

    expect($order)->toBe(['Already loose', ...$titles]);
});

it('leaves the positions sparse enough to insert between', function (): void {
    [$section, $project, $actor] = columnOf(4);

    app(DeleteSection::class)->handle($section, $actor);

    $positions = $project->placements()->whereNull('section_id')->orderBy('position')->pluck('position')->all();

    $gaps = [];

    foreach (array_slice($positions, 1) as $index => $position) {
        $gaps[] = $position - $positions[$index];
    }

    expect($gaps)->each->toBe(TaskProjectMembership::POSITION_GAP);
});
