<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskCollaborator;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/**
 * @return array{Task, User}
 */
function collaboratorEndpointTask(): array
{
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);

    return [Task::factory()->in($workspace)->create(), $actor];
}

it('adds and removes a collaborator', function (): void {
    [$task, $actor] = collaboratorEndpointTask();
    $collaborator = memberOf($task->workspace);

    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->post(route('tasks.collaborators.store', $task), ['user_id' => $collaborator->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('tasks.show', $task));

    expect($task->collaborators()->pluck('users.id')->all())->toBe([$collaborator->id]);

    $this->actingAs($actor)
        ->delete(route('tasks.collaborators.destroy', [$task, $collaborator->id]))
        ->assertRedirect();

    expect($task->collaborations()->count())->toBe(0);
});

it('refuses a person outside the workspace, and no person at all, as validation errors', function (): void {
    [$task, $actor] = collaboratorEndpointTask();
    $stranger = memberOf(Workspace::factory()->create());

    $this->actingAs($actor)
        ->post(route('tasks.collaborators.store', $task), ['user_id' => $stranger->id])
        ->assertSessionHasErrors('user_id');

    $this->actingAs($actor)
        ->post(route('tasks.collaborators.store', $task), [])
        ->assertSessionHasErrors('user_id');

    expect($task->collaborations()->count())->toBe(0);
});

it('answers the domain refusing the assignee with its sentence', function (): void {
    [$task, $actor] = collaboratorEndpointTask();
    $assignee = memberOf($task->workspace);
    $task->forceFill(['assignee_id' => $assignee->id])->save();

    $this->actingAs($actor)
        ->post(route('tasks.collaborators.store', $task), ['user_id' => $assignee->id])
        ->assertSessionHasErrors(['refusal' => 'That person is already assigned to this task.']);

    expect($task->collaborations()->count())->toBe(0);
});

it('lets a collaborator step off without the right to assign, and nobody else', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $colleague = memberOf($workspace, WorkspaceRole::Member);
    $project = Project::factory()->in($workspace)->create();
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Viewer)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();
    TaskCollaborator::factory()->on($task, $guest)->create();
    TaskCollaborator::factory()->on($task, $colleague)->create();

    $this->actingAs($guest)
        ->delete(route('tasks.collaborators.destroy', [$task, $colleague->id]))
        ->assertForbidden();

    $this->actingAs($guest)
        ->delete(route('tasks.collaborators.destroy', [$task, $guest->id]))
        ->assertRedirect();

    expect($task->collaborators()->pluck('users.id')->all())->toBe([$colleague->id]);
});

it('hides another workspace\'s task at both endpoints', function (): void {
    [$task] = collaboratorEndpointTask();
    $collaborator = memberOf($task->workspace);
    TaskCollaborator::factory()->on($task, $collaborator)->create();
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    $this->actingAs($outsider)
        ->post(route('tasks.collaborators.store', $task), ['user_id' => $outsider->id])
        ->assertNotFound();

    $this->actingAs($outsider)
        ->delete(route('tasks.collaborators.destroy', [$task, $collaborator->id]))
        ->assertNotFound();

    $this->actingAs($outsider)
        ->delete(route('tasks.collaborators.destroy', [$task, $outsider->id]))
        ->assertNotFound();

    expect($task->collaborators()->pluck('users.id')->all())->toBe([$collaborator->id]);
});

it('sends a visitor who is not signed in to the login', function (): void {
    [$task] = collaboratorEndpointTask();
    $collaborator = memberOf($task->workspace);
    TaskCollaborator::factory()->on($task, $collaborator)->create();

    $this->post(route('tasks.collaborators.store', $task), ['user_id' => $collaborator->id])
        ->assertRedirect(route('login'));

    $this->delete(route('tasks.collaborators.destroy', [$task, $collaborator->id]))
        ->assertRedirect(route('login'));

    expect($task->collaborations()->count())->toBe(1);
});

it('does not route a collaborator that is not a number', function (): void {
    [$task, $actor] = collaboratorEndpointTask();

    $this->actingAs($actor)->delete("/tasks/{$task->id}/collaborators/somebody")->assertNotFound();
});
