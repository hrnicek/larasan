<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Tag\Actions\AttachTagToTask;
use App\Domain\Tag\Actions\DetachTagFromTask;
use App\Domain\Tag\Events\TaskTagged;
use App\Domain\Tag\Events\TaskUntagged;
use App\Domain\Tag\Exceptions\TagException;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Event;

function tagTask(Task $task, Tag $tag, User $actor): void
{
    app(AttachTagToTask::class)->handle($task, $tag, $actor);
}

function untagTask(Task $task, Tag $tag, User $actor): void
{
    app(DetachTagFromTask::class)->handle($task, $tag, $actor);
}

it('says what a task is about', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $tag = Tag::factory()->in($workspace)->named('Bug')->create();

    tagTask($task, $tag, $actor);

    expect($task->tags()->pluck('name')->all())->toBe(['Bug']);
});

it('treats a second attach as the same tag', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $tag = Tag::factory()->in($workspace)->create();

    tagTask($task, $tag, $actor);

    Event::fake();
    tagTask($task, $tag, $actor);

    // Nothing changed, so nothing happened.
    expect($task->tags()->count())->toBe(1);
    Event::assertNotDispatched(TaskTagged::class);
});

it('refuses a tag from another workspace', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $elsewhere = Tag::factory()->create();

    /*
     * Two valid ids that must not be combined. The foreign keys prove each row exists; only the
     * Action proves they belong to the same tenant, because the pivot cannot say it.
     */
    expect(fn () => tagTask($task, $elsewhere, $actor))
        ->toThrow(TagException::class, 'That tag is not in this workspace.');

    expect($task->tags()->count())->toBe(0);
});

it('refuses somebody who may not edit the task', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $task = Task::factory()->in($workspace)->create();
    $tag = Tag::factory()->in($workspace)->create();

    // Tagging a task is editing it, so it asks exactly what every other edit asks.
    expect(fn () => tagTask($task, $tag, $guest))
        ->toThrow(TagException::class, 'You do not have permission to change this task.');
});

it('refuses somebody who cannot reach the task', function (): void {
    $workspace = Workspace::factory()->create();
    $outsider = memberOf($workspace, WorkspaceRole::Member);
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();
    $tag = Tag::factory()->in($workspace)->create();

    // The capability is not enough; `TaskPolicy::update()` answers reach first (TASK-070-017).
    expect(fn () => tagTask($task, $tag, $outsider))->toThrow(TagException::class);
});

it('announces a tag and an untag', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $tag = Tag::factory()->in($workspace)->create();

    Event::fake();
    tagTask($task, $tag, $actor);

    Event::assertDispatched(TaskTagged::class, fn (TaskTagged $event): bool => $event->taskId === $task->id
        && $event->tagId === $tag->id
        && $event->workspaceId === $workspace->id
        && $event->taggedById === $actor->id);
});

it('takes a tag off', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $tag = Tag::factory()->in($workspace)->create();
    tagTask($task, $tag, $actor);

    Event::fake();
    untagTask($task, $tag, $actor);

    expect($task->tags()->count())->toBe(0);
    Event::assertDispatched(TaskUntagged::class);
});

it('says nothing when the tag was not there', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $tag = Tag::factory()->in($workspace)->create();

    Event::fake();
    untagTask($task, $tag, $actor);

    // The outcome they asked for is already true — the shape `UnfollowTask` has.
    Event::assertNotDispatched(TaskUntagged::class);
});

it('refuses an untag by somebody who may not edit the task', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $task = Task::factory()->in($workspace)->create();
    $tag = Tag::factory()->in($workspace)->create();
    $owner = memberOf($workspace, WorkspaceRole::Owner);
    tagTask($task, $tag, $owner);

    expect(fn () => untagTask($task, $tag, $guest))->toThrow(TagException::class);

    expect($task->tags()->count())->toBe(1);
});

it('leaves other tasks tagged', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $other = Task::factory()->in($workspace)->create();
    $tag = Tag::factory()->in($workspace)->create();

    tagTask($task, $tag, $actor);
    tagTask($other, $tag, $actor);
    untagTask($task, $tag, $actor);

    expect($other->tags()->count())->toBe(1);
});
