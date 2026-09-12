<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Actions\FollowTask;
use App\Domain\Task\Actions\UnfollowTask;
use App\Domain\Task\Events\TaskFollowed;
use App\Domain\Task\Events\TaskUnfollowed;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskFollower;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Event;

function follow(Task $task, User $user): TaskFollower
{
    return app(FollowTask::class)->handle($task, $user);
}

function unfollow(Task $task, User $user): void
{
    app(UnfollowTask::class)->handle($task, $user);
}

it('starts watching a task', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $follow = follow($task, $actor);

    expect($follow->task_id)->toBe($task->id)
        ->and($task->followers()->pluck('users.id')->all())->toBe([$actor->id]);
});

it('treats a second follow as the same follow', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $first = follow($task, $actor);
    $second = follow($task, $actor);

    expect($second->id)->toBe($first->id)
        ->and($task->follows()->count())->toBe(1);
});

it('announces a new follow and says nothing the second time', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    Event::fake();
    follow($task, $actor);

    Event::assertDispatched(TaskFollowed::class, fn (TaskFollowed $event): bool => $event->taskId === $task->id
        && $event->workspaceId === $workspace->id
        && $event->followerId === $actor->id);

    Event::fake();
    follow($task, $actor);

    Event::assertNotDispatched(TaskFollowed::class);
});

it('lets anybody who can read the task follow it', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Viewer)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    expect(follow($task, $guest)->exists)->toBeTrue();
});

it('refuses to subscribe somebody who cannot reach the task', function (): void {
    $workspace = Workspace::factory()->create();
    $outsider = memberOf($workspace, WorkspaceRole::Member);
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();

    expect(fn (): TaskFollower => follow($task, $outsider))
        ->toThrow(TaskException::class, 'That person cannot reach this task.');

    expect($task->follows()->count())->toBe(0);
});

it('refuses somebody from another workspace entirely', function (): void {
    [$workspace] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $stranger = memberOf(Workspace::factory()->create());

    expect(fn (): TaskFollower => follow($task, $stranger))->toThrow(TaskException::class);
});

it('stops watching', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    follow($task, $actor);

    Event::fake();
    unfollow($task, $actor);

    expect($task->follows()->count())->toBe(0);
    Event::assertDispatched(TaskUnfollowed::class);
});

it('does nothing when they were not watching', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    Event::fake();
    unfollow($task, $actor);

    Event::assertNotDispatched(TaskUnfollowed::class);
});

it('lets somebody stop watching a task they can no longer reach', function (): void {
    $workspace = Workspace::factory()->create();
    $member = memberOf($workspace, WorkspaceRole::Member);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Workspace]);
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    follow($task, $member);

    $project->forceFill(['visibility' => ProjectVisibility::Private])->save();

    unfollow($task, $member);

    expect($task->follows()->count())->toBe(0);
});

it('leaves the other followers alone', function (): void {
    [$workspace, , $actor] = placeableProject();
    $other = memberOf($workspace, WorkspaceRole::Member);
    $task = Task::factory()->in($workspace)->create();

    follow($task, $actor);
    follow($task, $other);
    unfollow($task, $actor);

    expect($task->followers()->pluck('users.id')->all())->toBe([$other->id]);
});
