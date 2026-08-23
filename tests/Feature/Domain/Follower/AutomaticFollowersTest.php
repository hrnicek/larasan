<?php

declare(strict_types=1);

use App\Domain\Comment\Actions\CreateComment;
use App\Domain\Comment\Data\CreateCommentData;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Actions\AssignTask;
use App\Domain\Task\Actions\UnfollowTask;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;

it('follows a task the moment somebody is given it', function (): void {
    [$workspace, , $actor] = placeableProject();
    $assignee = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    app(AssignTask::class)->handle($task, $actor, $assignee);

    // Nobody should have to remember to subscribe to their own work.
    expect($task->followers()->pluck('users.id')->all())->toBe([$assignee->id]);
});

it('leaves somebody watching a task that was taken back from them', function (): void {
    [$workspace, , $actor] = placeableProject();
    $assignee = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    app(AssignTask::class)->handle($task, $actor, $assignee);
    app(AssignTask::class)->handle($task, $actor, null);

    // Somebody handed a task and then handed it on may still want to know how it ends, and
    // stopping is a button they already have.
    expect($task->followers()->pluck('users.id')->all())->toBe([$assignee->id]);
});

it('follows a task the moment somebody says something about it', function (): void {
    [$workspace, , $author] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    app(CreateComment::class)->handle($task, $author, new CreateCommentData(body: 'Looks right to me'));

    // Joining a conversation and hearing none of the replies is the worst of both.
    expect($task->followers()->pluck('users.id')->all())->toBe([$author->id]);
});

it('adds nobody twice', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    app(AssignTask::class)->handle($task, $actor, $actor);
    app(CreateComment::class)->handle($task, $actor, new CreateCommentData(body: 'Mine then'));
    app(CreateComment::class)->handle($task, $actor, new CreateCommentData(body: 'And again'));

    expect($task->follows()->count())->toBe(1);
});

it('starts watching again when somebody who had stopped comments', function (): void {
    [$workspace, , $author] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    app(CreateComment::class)->handle($task, $author, new CreateCommentData(body: 'First'));
    app(UnfollowTask::class)->handle($task, $author);

    app(CreateComment::class)->handle($task, $author, new CreateCommentData(body: 'Second'));

    /*
     * Deliberate: unfollowing is "not now" rather than "never again", and speaking is the
     * clearest statement of interest somebody can make.
     */
    expect($task->followers()->pluck('users.id')->all())->toBe([$author->id]);
});

it('follows for a guest exactly as for anybody else', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Viewer)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    app(CreateComment::class)->handle($task, $guest, new CreateCommentData(body: 'A question'));

    expect($task->followers()->pluck('users.id')->all())->toBe([$guest->id]);
});

it('leaves other people s watching alone', function (): void {
    [$workspace, , $actor] = placeableProject();
    $other = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    app(AssignTask::class)->handle($task, $actor, $other);
    app(CreateComment::class)->handle($task, $actor, new CreateCommentData(body: 'Handing this over'));

    expect($task->followers()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$actor->id, $other->id])->sort()->values()->all());
});
