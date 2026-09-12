<?php

declare(strict_types=1);

use App\Domain\Task\Actions\UpdateTask;
use App\Domain\Task\Ancestry\ParentChain;
use App\Domain\Task\Data\UpdateTaskData;
use App\Domain\Task\Models\Task;

it('accepts a chain one level short of the limit', function (): void {
    [$root, $actor] = taskEditableBy();

    $deepest = $root;

    foreach (range(2, ParentChain::MAX_DEPTH - 1) as $level) {
        $deepest = Task::factory()->childOf($deepest)->create();
    }

    $leaf = Task::factory()->in($root->workspace)->create();

    app(UpdateTask::class)->handle($leaf, $actor, new UpdateTaskData(title: $leaf->title, parentId: $deepest->id));

    expect($leaf->fresh()?->parent_id)->toBe($deepest->id);
});

it('refuses a loop through the endpoint, at every depth', function (int $depth): void {
    [$root, $actor] = taskEditableBy();

    $descendant = $root;

    foreach (range(1, $depth) as $level) {
        $descendant = Task::factory()->childOf($descendant)->create();
    }

    $this->actingAs($actor)
        ->from(route('dashboard'))
        ->put(route('tasks.update', $root), ['title' => $root->title, 'parent_id' => $descendant->id])
        ->assertSessionHasErrors('parent_id');

    expect($root->fresh()?->parent_id)->toBeNull();
})->with([
    'child' => 1,
    'grandchild' => 2,
    'four levels down' => 4,
]);

it('refuses a task as its own parent at the request, before the Action is asked', function (): void {
    [$task, $actor] = taskEditableBy();

    $this->actingAs($actor)
        ->from(route('dashboard'))
        ->put(route('tasks.update', $task), ['title' => $task->title, 'parent_id' => $task->id])
        ->assertSessionHasErrors('parent_id');
});

it('still allows re-parenting inside a deep tree', function (): void {
    [$root, $actor] = taskEditableBy();
    $branchA = Task::factory()->childOf($root)->create();
    $branchB = Task::factory()->childOf($root)->create();
    $leaf = Task::factory()->childOf($branchA)->create();

    app(UpdateTask::class)->handle($leaf, $actor, new UpdateTaskData(title: $leaf->title, parentId: $branchB->id));

    expect($leaf->fresh()?->parent_id)->toBe($branchB->id)
        ->and($branchA->fresh()?->parent_id)->toBe($root->id);
});

it('leaves the tree untouched when a loop is refused', function (): void {
    [$root, $actor] = taskEditableBy();
    $child = Task::factory()->childOf($root)->create();
    $grandchild = Task::factory()->childOf($child)->create();

    $this->actingAs($actor)
        ->put(route('tasks.update', $root), ['title' => $root->title, 'parent_id' => $grandchild->id]);

    expect($root->fresh()?->parent_id)->toBeNull()
        ->and($child->fresh()?->parent_id)->toBe($root->id)
        ->and($grandchild->fresh()?->parent_id)->toBe($child->id);
});
