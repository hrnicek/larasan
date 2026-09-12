<?php

declare(strict_types=1);

use App\Domain\Task\Actions\UpdateTask;
use App\Domain\Task\Ancestry\ParentChain;
use App\Domain\Task\Data\UpdateTaskData;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

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

it('counts the subtasks a task brings with it against the depth limit', function (int $levelsBelow): void {
    [$root, $actor] = taskEditableBy();

    $target = $root;

    foreach (range(1, ParentChain::MAX_DEPTH - 1 - $levelsBelow) as $level) {
        $target = Task::factory()->childOf($target)->create();
    }

    $moved = Task::factory()->in($root->workspace)->create();
    $leaf = $moved;

    foreach (range(1, $levelsBelow) as $level) {
        $leaf = Task::factory()->childOf($leaf)->create();
    }

    expect(fn (): Task => app(UpdateTask::class)->handle($moved, $actor, new UpdateTaskData(parentId: $target->id, fields: ['parent_id'])))
        ->toThrow(TaskException::class, 'nested that deeply');

    expect($moved->fresh()?->parent_id)->toBeNull();

    $shallower = $target->parent;

    app(UpdateTask::class)->handle($moved, $actor, new UpdateTaskData(parentId: $shallower?->id, fields: ['parent_id']));

    expect($moved->fresh()?->parent_id)->toBe($shallower?->id);
})->with([
    'one level below' => 1,
    'five levels below' => 5,
]);

it('locks the moved task and the whole chain above its new parent, in key order, before it decides', function (): void {
    [$root, $actor] = taskEditableBy();
    $middle = Task::factory()->childOf($root)->create();
    $parent = Task::factory()->childOf($middle)->create();
    $moved = Task::factory()->in($root->workspace)->create();

    $locks = [];

    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        if (str_contains($query->sql, 'for update') && str_contains($query->sql, 'from "tasks"')) {
            $locks[] = $query;
        }
    });

    app(UpdateTask::class)->handle($moved, $actor, new UpdateTaskData(parentId: $parent->id, fields: ['parent_id']));

    expect($locks)->not->toBeEmpty();

    $lock = $locks[array_key_last($locks)];
    $ids = [$root->id, $middle->id, $parent->id, $moved->id];

    expect($lock->bindings)->toContain(...$ids)
        ->and($lock->sql)->toContain('order by "id" asc')
        ->and($moved->fresh()?->parent_id)->toBe($parent->id);
});

it('sees a chain that changed between reading it and locking it', function (): void {
    [$moved, $actor] = taskEditableBy();
    $parent = Task::factory()->in($moved->workspace)->create();
    $elsewhere = Task::factory()->in($moved->workspace)->create();
    $changed = false;

    DB::listen(function (QueryExecuted $query) use (&$changed, $parent, $elsewhere, $moved): void {
        if ($changed || str_contains($query->sql, 'for update') || ! str_starts_with($query->sql, 'select "parent_id" from "tasks"')) {
            return;
        }

        if (! in_array($parent->id, $query->bindings, true)) {
            return;
        }

        $changed = true;
        Task::query()->whereKey($elsewhere->id)->update(['parent_id' => $moved->id]);
        Task::query()->whereKey($parent->id)->update(['parent_id' => $elsewhere->id]);
    });

    expect(fn (): Task => app(UpdateTask::class)->handle($moved, $actor, new UpdateTaskData(parentId: $parent->id, fields: ['parent_id'])))
        ->toThrow(TaskException::class, 'through its own subtasks');

    expect($changed)->toBeTrue()
        ->and($moved->fresh()?->parent_id)->toBeNull();
});
