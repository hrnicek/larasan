<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('casts what it stores', function (): void {
    $task = Task::factory()
        ->withPriority(TaskPriority::Urgent)
        ->dueAt(CarbonImmutable::parse('2026-04-01 09:00:00'))
        ->create();

    $fresh = $task->fresh();

    expect($fresh?->priority)->toBe(TaskPriority::Urgent)
        ->and($fresh?->due_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and(DB::table('tasks')->where('id', $task->id)->value('priority'))->toBe('urgent');
});

it('reads completion from the column and nowhere else', function (): void {
    $open = Task::factory()->create();
    $done = Task::factory()->completed()->create();

    expect($open->isCompleted())->toBeFalse()
        ->and($done->isCompleted())->toBeTrue()
        ->and($done->completed_by)->not->toBeNull()
        ->and(Task::query()->open()->pluck('id')->all())->toBe([$open->id]);
});

it('refuses to fill what an Action owns', function (): void {
    $workspace = Workspace::factory()->create();
    $intruder = User::factory()->create();

    $task = new Task([
        'title' => 'Filled',
        'parent_id' => null,
        'description' => null,
        'priority' => TaskPriority::Low,
        'due_at' => null,
        'assignee_id' => $intruder->id,
    ]);

    $task->workspace_id = $workspace->id;
    $task->save();

    expect($task->fresh()?->assignee_id)->toBe($intruder->id)
        ->and($task->fresh()?->completed_at)->toBeNull()
        ->and($task->fresh()?->created_by)->toBeNull();
});

it('belongs to a workspace and to no project', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();

    expect($task->workspace->is($workspace))->toBeTrue()
        ->and($workspace->tasks()->pluck('id')->all())->toBe([$task->id])
        ->and(method_exists($task, 'project'))->toBeFalse()
        ->and(method_exists($task, 'section'))->toBeFalse();
});

it('links a parent to its children in a stable order', function (): void {
    $parent = Task::factory()->create();
    $first = Task::factory()->childOf($parent)->create();
    $second = Task::factory()->childOf($parent)->create();

    expect($parent->children()->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($first->parent?->is($parent))->toBeTrue()
        ->and($first->workspace_id)->toBe($parent->workspace_id);
});

it('loads its people without a query per row', function (): void {
    $workspace = Workspace::factory()->create();
    $assignee = User::factory()->create();
    Task::factory()->in($workspace)->assignedTo($assignee)->count(3)->create();

    // Eloquent only arms the lazy-loading guard for result sets with more than one row.
    $tasks = Task::query()->with('assignee')->get();

    expect($tasks)->toHaveCount(3)
        ->and($tasks->map(fn (Task $task): ?string => $task->assignee?->name)->filter())->toHaveCount(3);
});

it('keeps a soft-deleted task out of ordinary reads', function (): void {
    $task = Task::factory()->create();

    $task->delete();

    expect(Task::query()->count())->toBe(0)
        ->and(Task::query()->withTrashed()->count())->toBe(1);
});
