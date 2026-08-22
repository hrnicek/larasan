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
use App\Domain\Task\Actions\FollowTask;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function commentOn(Task $task, User $author, string $body = 'Looks right to me'): void
{
    app(CreateComment::class)->handle($task, $author, new CreateCommentData(body: $body));
}

/**
 * @return list<int>
 */
function notifiedAbout(): array
{
    /** @var list<int> $ids */
    $ids = DB::table('notifications')->orderBy('notifiable_id')->pluck('notifiable_id')->all();

    return $ids;
}

it('tells the assignee even though nobody asked them to follow their own work', function (): void {
    [$workspace, , $author] = placeableProject();
    $assignee = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create(['assignee_id' => $assignee->id]);

    commentOn($task, $author);

    expect(notifiedAbout())->toBe([$assignee->id]);
});

it('tells somebody once when they are both the assignee and a follower', function (): void {
    [$workspace, , $author] = placeableProject();
    $assignee = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create(['assignee_id' => $assignee->id]);
    app(FollowTask::class)->handle($task, $assignee);

    commentOn($task, $author);

    // Two reasons to hear about something is not two notifications.
    expect(notifiedAbout())->toBe([$assignee->id]);
});

it('tells the followers and the assignee together', function (): void {
    [$workspace, , $author] = placeableProject();
    $assignee = memberOf($workspace);
    $watcher = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create(['assignee_id' => $assignee->id]);
    app(FollowTask::class)->handle($task, $watcher);

    commentOn($task, $author);

    $expected = collect([$assignee->id, $watcher->id])->sort()->values()->all();

    expect(notifiedAbout())->toBe($expected);
});

it('says nothing to somebody who can no longer reach the task', function (): void {
    $workspace = Workspace::factory()->create();
    $author = memberOf($workspace, WorkspaceRole::Owner);
    $watcher = memberOf($workspace, WorkspaceRole::Member);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Workspace]);
    ProjectMembership::factory()->in($project)->forUser($author)->withAccess(ProjectAccessLevel::Owner)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    app(FollowTask::class)->handle($task, $watcher);

    /*
     * They followed it while they could open it, and the project has since become private. This
     * is the other half of the rule `FollowTask` enforces on the way in: an inbox full of work
     * nobody can open is worse than no notification at all (TASK-070-017).
     */
    $project->forceFill(['visibility' => ProjectVisibility::Private])->save();

    commentOn($task, $author);

    expect(notifiedAbout())->toBe([]);
});

it('says nothing to an assignee who cannot reach the task either', function (): void {
    $workspace = Workspace::factory()->create();
    $author = memberOf($workspace, WorkspaceRole::Owner);
    $assignee = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($project)->forUser($author)->withAccess(ProjectAccessLevel::Owner)->create();
    $task = Task::factory()->in($workspace)->create(['assignee_id' => $assignee->id]);
    TaskProjectMembership::factory()->placing($task, $project)->create();

    // A guest reaches only what they were given, and nobody gave them this project.
    commentOn($task, $author);

    expect(notifiedAbout())->toBe([]);
});

it('still tells a follower who was never assigned anything', function (): void {
    [$workspace, , $author] = placeableProject();
    $watcher = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();
    app(FollowTask::class)->handle($task, $watcher);

    commentOn($task, $author);

    expect(notifiedAbout())->toBe([$watcher->id]);
});

it('says nothing to the author even when they are the assignee', function (): void {
    [$workspace, , $author] = placeableProject();
    $task = Task::factory()->in($workspace)->create(['assignee_id' => $author->id]);
    app(FollowTask::class)->handle($task, $author);

    commentOn($task, $author);

    expect(notifiedAbout())->toBe([]);
});
