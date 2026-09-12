<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

it('requires authentication', function (): void {
    [$task] = taskEditableBy();

    $this->post(route('tasks.store'), ['title' => 'New'])->assertRedirect(route('login'));
    $this->put(route('tasks.update', $task), ['title' => 'New'])->assertRedirect(route('login'));
});

it('creates a task in the current workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);

    $this->actingAs($actor)
        ->from(route('dashboard'))
        ->post(route('tasks.store'), ['title' => 'Write it down', 'priority' => TaskPriority::High->value])
        ->assertRedirect(route('dashboard'));

    $task = $workspace->tasks()->sole();

    expect($task->title)->toBe('Write it down')
        ->and($task->priority)->toBe(TaskPriority::High)
        ->and($task->created_by)->toBe($actor->id);
});

it('updates, completes, reopens, assigns and deletes', function (): void {
    [$task, $actor] = taskEditableBy();
    $assignee = memberOf($task->workspace, WorkspaceRole::Member);

    $this->actingAs($actor)->put(route('tasks.update', $task), ['title' => 'Renamed'])->assertRedirect();
    expect($task->fresh()?->title)->toBe('Renamed');

    $this->actingAs($actor)->put(route('tasks.complete', $task))->assertRedirect();
    expect($task->fresh()?->isCompleted())->toBeTrue();

    $this->actingAs($actor)->delete(route('tasks.reopen', $task))->assertRedirect();
    expect($task->fresh()?->isCompleted())->toBeFalse();

    $this->actingAs($actor)->put(route('tasks.assign', $task), ['assignee_id' => $assignee->id])->assertRedirect();
    expect($task->fresh()?->assignee_id)->toBe($assignee->id);

    $this->actingAs($actor)->put(route('tasks.assign', $task), [])->assertRedirect();
    expect($task->fresh()?->assignee_id)->toBeNull();

    $this->actingAs($actor)->delete(route('tasks.destroy', $task))->assertRedirect();
    expect(Task::query()->whereKey($task->id)->exists())->toBeFalse();
});

it('hides a task from another workspace behind a 404', function (string $method, string $name): void {
    [, $actor] = taskEditableBy();
    $theirs = Task::factory()->create(['title' => 'Untouched']);

    $this->actingAs($actor)
        ->call($method, route($name, $theirs), ['title' => 'Renamed'])
        ->assertNotFound();

    expect($theirs->fresh()?->title)->toBe('Untouched');
})->with([
    'update' => ['PUT', 'tasks.update'],
    'complete' => ['PUT', 'tasks.complete'],
    'assign' => ['PUT', 'tasks.assign'],
    'delete' => ['DELETE', 'tasks.destroy'],
]);

it('refuses a guest at every endpoint', function (string $method, string $name): void {
    [$task] = taskEditableBy();
    $guest = memberOf($task->workspace, WorkspaceRole::Guest);
    $task->forceFill(['title' => 'Untouched'])->save();

    $this->actingAs($guest)
        ->call($method, route($name, $task), ['title' => 'Renamed'])
        ->assertForbidden();

    expect($task->fresh()?->title)->toBe('Untouched')
        ->and($task->fresh()?->isCompleted())->toBeFalse();
})->with([
    'update' => ['PUT', 'tasks.update'],
    'complete' => ['PUT', 'tasks.complete'],
    'assign' => ['PUT', 'tasks.assign'],
    'delete' => ['DELETE', 'tasks.destroy'],
]);

it('refuses to create a task for somebody with no workspace at all', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('tasks.store'), ['title' => 'Write it down'])
        ->assertForbidden();

    expect(Task::query()->count())->toBe(0);
});

it('answers a validation error rather than a domain exception for a foreign parent', function (): void {
    [$task, $actor] = taskEditableBy();
    $foreign = Task::factory()->create();

    $this->actingAs($actor)
        ->from(route('dashboard'))
        ->put(route('tasks.update', $task), ['title' => 'Renamed', 'parent_id' => $foreign->id])
        ->assertSessionHasErrors('parent_id');
});

it('answers a domain refusal for a loop the validator cannot see', function (): void {
    [$task, $actor] = taskEditableBy();
    $child = Task::factory()->childOf($task)->create();
    $grandchild = Task::factory()->childOf($child)->create();

    $this->actingAs($actor)
        ->from(route('dashboard'))
        ->put(route('tasks.update', $task), ['title' => 'Renamed', 'parent_id' => $grandchild->id])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors('parent_id');

    expect($task->fresh()?->parent_id)->toBeNull()
        ->and($task->fresh()?->title)->not->toBe('Renamed');
});

it('rejects a malformed task id at routing', function (): void {
    [, $actor] = taskEditableBy();

    $this->actingAs($actor)->put('tasks/not-a-uuid', ['title' => 'Renamed'])->assertNotFound();
});
