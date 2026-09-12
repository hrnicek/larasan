<?php

declare(strict_types=1);

use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function attachTagRow(Task $task, Tag $tag): void
{
    DB::table('task_tag')->insert(['task_id' => $task->id, 'tag_id' => $tag->id]);
}

it('carries no workspace of its own', function (): void {
    // Scoped through its task and tag; the Action ensures both share a workspace. See ADR-0005.
    expect(Schema::hasColumn('task_tag', 'workspace_id'))->toBeFalse();
});

it('refuses the same tag on the same task twice', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $tag = Tag::factory()->in($workspace)->create();

    attachTagRow($task, $tag);

    expect(fn () => DB::transaction(fn () => attachTagRow($task, $tag)))->toThrow(QueryException::class);
});

it('goes when the task goes', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    attachTagRow($task, Tag::factory()->in($workspace)->create());

    $task->forceDelete();

    expect(DB::table('task_tag')->count())->toBe(0);
});

it('goes when the tag goes', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $tag = Tag::factory()->in($workspace)->create();
    attachTagRow($task, $tag);

    $tag->delete();

    expect(DB::table('task_tag')->count())->toBe(0)
        ->and(Task::query()->whereKey($task->id)->exists())->toBeTrue();
});

it('stays while the task is only soft-deleted', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    attachTagRow($task, Tag::factory()->in($workspace)->create());

    $task->delete();

    expect(DB::table('task_tag')->count())->toBe(1);
});

it('reads a task s tags in a stable order', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();

    foreach (['Urgent', 'Bug', 'Docs'] as $name) {
        attachTagRow($task, Tag::factory()->in($workspace)->named($name)->create());
    }

    expect($task->tags()->pluck('name')->all())->toBe(['Bug', 'Docs', 'Urgent']);
});

it('indexes the side the primary key does not lead with', function (): void {
    $indexes = collect(Schema::getIndexes('task_tag'))->pluck('columns');

    // PostgreSQL does not index the referencing side of a foreign key; the primary key leads with task_id.
    expect($indexes)->toContain(['task_id', 'tag_id'])
        ->and($indexes)->toContain(['tag_id']);
});
