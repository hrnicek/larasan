<?php

declare(strict_types=1);

use App\Domain\Placement\Actions\DetachTaskFromProject;
use App\Domain\Placement\Events\TaskDetachedFromProject;
use App\Domain\Placement\Exceptions\PlacementException;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

const ONLY_PRIVATE_PROJECT = 'This task is only in this private project. Add it to another project first, or delete it.';

/**
 * @return array{Workspace, Project, User, Task, TaskProjectMembership}
 */
function taskOnlyInAPrivateProject(): array
{
    $workspace = Workspace::factory()->create();
    $owner = memberOf($workspace, WorkspaceRole::Member);
    $private = Project::factory()->in($workspace)->private()->create();
    ProjectMembership::factory()->in($private)->forUser($owner)->withAccess(ProjectAccessLevel::Owner)->create();

    $task = Task::factory()->in($workspace)->create(['title' => 'Negotiate severance']);
    $placement = TaskProjectMembership::factory()->placing($task, $private)->create();

    return [$workspace, $private, $owner, $task, $placement];
}

it('refuses to take a task out of the only project it is in when that project is private', function (): void {
    [$workspace, $private, $owner, $task, $placement] = taskOnlyInAPrivateProject();
    $colleague = memberOf($workspace, WorkspaceRole::Member);

    Event::fake([TaskDetachedFromProject::class]);

    expect(fn () => app(DetachTaskFromProject::class)->handle($task, $private, $owner))
        ->toThrow(PlacementException::class, ONLY_PRIVATE_PROJECT);

    Event::assertNotDispatched(TaskDetachedFromProject::class);

    expect($placement->fresh())->not->toBeNull()
        ->and(Gate::forUser($colleague)->allows('view', $task))->toBeFalse();
});

it('takes a task out of a private project while it stays in another one', function (): void {
    [$workspace, $private, $owner, $task] = taskOnlyInAPrivateProject();
    $open = Project::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $open)->create();

    app(DetachTaskFromProject::class)->handle($task, $private, $owner);

    expect($task->placements()->pluck('project_id')->all())->toBe([$open->id]);
});

it('still takes a task out of the only project it is in when that project is open to the workspace', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    app(DetachTaskFromProject::class)->handle($task, $project, $actor);

    expect($task->placements()->count())->toBe(0);
});

it('refuses the same removal over HTTP and keeps the card', function (): void {
    [, , $owner, , $placement] = taskOnlyInAPrivateProject();

    Event::fake([TaskDetachedFromProject::class]);

    $this->actingAs($owner)
        ->from(route('dashboard'))
        ->delete(route('placements.destroy', $placement))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors(['refusal' => ONLY_PRIVATE_PROJECT]);

    Event::assertNotDispatched(TaskDetachedFromProject::class);

    expect($placement->fresh())->not->toBeNull();
});
