<?php

declare(strict_types=1);

use App\Domain\Placement\Actions\CreateTaskInProject;
use App\Domain\Placement\Exceptions\PlacementException;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Data\CreateTaskData;
use App\Domain\Task\Events\TaskAssigned;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

function addTask(Project $project, User $actor, string $title, ?Section $section = null): TaskProjectMembership
{
    return app(CreateTaskInProject::class)->handle($project, $actor, new CreateTaskData(title: $title), $section);
}

it('creates the task and the card it appears as', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();

    $placement = addTask($project, $actor, 'Write it down', $section);

    expect($placement->section_id)->toBe($section->id)
        ->and($placement->task->title)->toBe('Write it down')
        ->and($placement->task->workspace_id)->toBe($workspace->id)
        ->and($placement->task->created_by)->toBe($actor->id);
});

it('leaves a task in the ungrouped bucket when no column is named', function (): void {
    [, $project, $actor] = placeableProject();

    $placement = addTask($project, $actor, 'Unfiled');

    expect($placement->isUngrouped())->toBeTrue()
        ->and($placement->position)->toBe(SparsePosition::GAP);
});

it('appends each new task to the end of its column', function (): void {
    [, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();

    $positions = collect(['A', 'B', 'C'])
        ->map(fn (string $title): int => addTask($project, $actor, $title, $section)->position)
        ->all();

    expect($positions)->toBe(SparsePosition::spread(3))
        ->and($section->placements()->with('task')->get()->map(fn (TaskProjectMembership $card): string => (string) $card->task->title)->all())
        ->toBe(['A', 'B', 'C']);
});

it('writes both halves or neither', function (): void {
    [, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();

    DB::table('task_project_memberships')->insert([
        'id' => (string) Str::uuid7(),
        'task_id' => Task::factory()->in($project->workspace)->create()->id,
        'project_id' => $project->id,
        'section_id' => $section->id,
        'position' => SparsePosition::GAP,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::statement('DROP INDEX task_project_memberships_slot_unique');
    DB::statement('CREATE UNIQUE INDEX task_project_memberships_slot_unique ON task_project_memberships (project_id, section_id) WHERE section_id IS NOT NULL');

    expect(fn (): TaskProjectMembership => addTask($project, $actor, 'Doomed', $section))
        ->toThrow(QueryException::class);

    expect(Task::query()->where('title', 'Doomed')->exists())->toBeFalse();
});

it('refuses a column from another project', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $foreign = Section::factory()->in(Project::factory()->in($workspace)->create())->create();

    expect(fn (): TaskProjectMembership => addTask($project, $actor, 'Elsewhere', $foreign))
        ->toThrow(PlacementException::class, 'That section is not in this project.');

    expect(Task::query()->count())->toBe(0);
});

it('refuses somebody who may only view the project', function (): void {
    [, $project, $viewer] = placeableProject(ProjectAccessLevel::Viewer);

    expect(fn (): TaskProjectMembership => addTask($project, $viewer, 'Not mine'))
        ->toThrow(PlacementException::class);

    expect(Task::query()->count())->toBe(0);
});

it('refuses an archived project', function (): void {
    [, $project, $actor] = placeableProject();
    $project->forceFill(['archived_at' => now()])->save();

    expect(fn (): TaskProjectMembership => addTask($project->refresh(), $actor, 'Too late'))
        ->toThrow(PlacementException::class);
});

it('creates a task from the endpoint, in the column the request named', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();
    $assignee = memberOf($workspace, WorkspaceRole::Member);

    $this->actingAs($actor)
        ->from(route('projects.show', $project))
        ->post(route('projects.tasks.store', $project), [
            'title' => 'From the board',
            'priority' => TaskPriority::High->value,
            'section' => $section->id,
            'assignee_id' => $assignee->id,
        ])
        ->assertRedirect(route('projects.show', $project));

    $card = $section->placements()->with('task')->sole();

    expect($card->task->title)->toBe('From the board')
        ->and($card->task->priority)->toBe(TaskPriority::High)
        ->and($card->task->assignee_id)->toBe($assignee->id);
});

it('rejects a column from another project at the endpoint', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $foreign = Section::factory()->in(Project::factory()->in($workspace)->create())->create();

    $this->actingAs($actor)
        ->from(route('projects.show', $project))
        ->post(route('projects.tasks.store', $project), ['title' => 'Nope', 'section' => $foreign->id])
        ->assertSessionHasErrors('section');

    expect(Task::query()->count())->toBe(0);
});

it('rejects a task with no title', function (): void {
    [, $project, $actor] = placeableProject();

    $this->actingAs($actor)
        ->from(route('projects.show', $project))
        ->post(route('projects.tasks.store', $project), ['title' => ' '])
        ->assertSessionHasErrors('title');
});

it('refuses the endpoint to a viewer', function (): void {
    [, $project, $viewer] = placeableProject(ProjectAccessLevel::Viewer);

    $this->actingAs($viewer)
        ->post(route('projects.tasks.store', $project), ['title' => 'Not mine'])
        ->assertForbidden();
});

it('hides the endpoint of a project in another workspace', function (): void {
    [, , $actor] = placeableProject();
    [, $elsewhere] = placeableProject();

    $this->actingAs($actor)
        ->post(route('projects.tasks.store', $elsewhere), ['title' => 'Not yours'])
        ->assertNotFound();

    expect(Workspace::query()->count())->toBe(2);
});

it('refuses an assignee who cannot open the private project the task is created in', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $project = Project::factory()->in($workspace)->private()->create();
    ProjectMembership::factory()->in($project)->forUser($actor)->withAccess(ProjectAccessLevel::Editor)->create();
    $outsider = memberOf($workspace, WorkspaceRole::Member);

    expect(fn (): TaskProjectMembership => app(CreateTaskInProject::class)->handle(
        $project,
        $actor,
        new CreateTaskData(title: 'Not for them', assigneeId: $outsider->id),
    ))->toThrow(TaskException::class, 'That person cannot reach this task.');

    expect(Task::query()->count())->toBe(0);
});

it('assigns a guest who was given the project the task is created in, and says so', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Viewer)->create();
    Event::fake([TaskAssigned::class]);

    $placement = app(CreateTaskInProject::class)->handle(
        $project,
        $actor,
        new CreateTaskData(title: 'For the client', assigneeId: $guest->id),
    );

    expect($placement->task->assignee_id)->toBe($guest->id);

    Event::assertDispatched(TaskAssigned::class, fn (TaskAssigned $event): bool => $event->taskId === $placement->task_id
        && $event->assigneeId === $guest->id);
});

it('locks the project row before it locks or writes any card, when it files the new card in a column', function (): void {
    [, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();

    DB::flushQueryLog();
    DB::enableQueryLog();

    addTask($project, $actor, 'Filed straight away', $section);

    DB::disableQueryLog();

    $statements = collect(DB::getQueryLog())->pluck('query');

    $projectLock = $statements->search(fn (string $sql): bool => str_contains($sql, 'from "projects"')
        && str_ends_with($sql, 'for no key update'));

    $firstCardLockOrWrite = $statements->search(fn (string $sql): bool => str_contains($sql, '"task_project_memberships"')
        && (str_ends_with($sql, 'for update') || str_starts_with($sql, 'insert') || str_starts_with($sql, 'update')));

    expect($projectLock)->toBeInt()
        ->and($firstCardLockOrWrite)->toBeInt()
        ->and($projectLock)->toBeLessThan($firstCardLockOrWrite);
});
