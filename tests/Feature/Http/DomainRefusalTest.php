<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/**
 * A rule an Action refuses is a "no", not a crash. These are the refusals a FormRequest
 * cannot pre-check — the ones that depend on state the request would have to duplicate the
 * domain to know.
 *
 * @return array{Task, User, User}
 */
function unreachableAssignment(): array
{
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Owner);
    $guest = memberOf($workspace, WorkspaceRole::Guest);

    // The guest is an active member, so the request's `exists` rule passes; only the Action
    // knows they cannot reach a task that is in none of their projects (TASK-070-017).
    return [Task::factory()->in($workspace)->create(), $actor, $guest];
}

it('answers an action refusal with a message rather than a 500', function (): void {
    [$task, $actor, $guest] = unreachableAssignment();

    $this->actingAs($actor)
        ->from(route('dashboard'))
        ->put(route('tasks.assign', $task), ['assignee_id' => $guest->id])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors('refusal');

    expect($task->fresh()?->assignee_id)->toBeNull();
});

it('says why, in the sentence the domain wrote', function (): void {
    [$task, $actor, $guest] = unreachableAssignment();

    // The message is the one the named constructor wrote, which is why those constructors
    // write sentences rather than codes.
    $this->actingAs($actor)
        ->from(route('dashboard'))
        ->put(route('tasks.assign', $task), ['assignee_id' => $guest->id])
        ->assertSessionHasErrors(['refusal' => 'That person cannot reach this task.']);
});

it('answers a json client with 422 and the same message', function (): void {
    [$task, $actor, $guest] = unreachableAssignment();

    $this->actingAs($actor)
        ->putJson(route('tasks.assign', $task), ['assignee_id' => $guest->id])
        ->assertStatus(422)
        ->assertJson(['message' => 'That person cannot reach this task.']);
});
