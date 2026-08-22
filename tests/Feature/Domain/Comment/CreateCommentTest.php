<?php

declare(strict_types=1);

use App\Domain\Comment\Actions\CreateComment;
use App\Domain\Comment\Data\CreateCommentData;
use App\Domain\Comment\Events\CommentCreated;
use App\Domain\Comment\Exceptions\CommentException;
use App\Domain\Comment\Models\Comment;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Event;

function comment(Task $task, User $actor, string $body = 'Looks right to me'): Comment
{
    return app(CreateComment::class)->handle($task, $actor, new CreateCommentData(body: $body));
}

it('says something about a task', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $comment = comment($task, $actor, 'This is done, I think');

    expect($comment->body)->toBe('This is done, I think')
        ->and($comment->author_id)->toBe($actor->id)
        ->and($task->comments()->count())->toBe(1);
});

it('takes the workspace from the subject rather than from the request', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    /*
     * The actor is a member of two workspaces and happens to be "in" the other one. A comment
     * scoped to whichever workspace the actor was in would leak across tenants the first time
     * somebody followed a link from somewhere else.
     */
    $elsewhere = Workspace::factory()->create();
    memberOf($elsewhere, WorkspaceRole::Member, user: $actor);
    $actor->forceFill(['current_workspace_id' => $elsewhere->id])->save();

    expect(comment($task, $actor)->workspace_id)->toBe($workspace->id);
});

it('trims the body and refuses one with nothing in it', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    expect(comment($task, $actor, "  Spaced out  \n")->body)->toBe('Spaced out');

    // Whitespace is not a comment, and the Action says so rather than leaving it to a request
    // a console command never passes.
    expect(fn (): Comment => comment($task, $actor, "   \n  "))
        ->toThrow(CommentException::class, 'A comment needs something in it.');

    expect($task->comments()->count())->toBe(1);
});

it('announces the comment it created', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    Event::fake();
    $comment = comment($task, $actor);

    Event::assertDispatched(CommentCreated::class, fn (CommentCreated $event): bool => $event->commentId === $comment->id
        && $event->workspaceId === $workspace->id
        && $event->subjectType === 'task'
        && $event->subjectId === $task->id
        && $event->authorId === $actor->id);
});

it('lets a guest comment on what they were given', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Viewer)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    // `comment.create` is the one capability a guest's role holds (ADR-0010), and they were
    // given the project the task is in.
    expect(comment($task, $guest)->exists)->toBeTrue();
});

it('refuses somebody who cannot reach the subject', function (): void {
    $workspace = Workspace::factory()->create();
    $outsider = memberOf($workspace, WorkspaceRole::Member);
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();

    /*
     * The capability alone is not enough. Commenting into a project somebody was never given
     * is talking to people who cannot hear you — and reading what they say back.
     */
    expect(fn (): Comment => comment($task, $outsider))
        ->toThrow(CommentException::class, 'You cannot comment on something you cannot reach.');

    expect($task->comments()->count())->toBe(0);
});

it('refuses somebody whose workspace membership is not live', function (): void {
    $workspace = Workspace::factory()->create();
    $revoked = memberOf($workspace, WorkspaceRole::Member, WorkspaceMembershipStatus::Revoked);
    $task = Task::factory()->in($workspace)->create();

    expect(fn (): Comment => comment($task, $revoked))
        ->toThrow(CommentException::class, 'You do not have permission to comment in this workspace.');
});

it('refuses somebody from another workspace entirely', function (): void {
    [$workspace] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $stranger = memberOf(Workspace::factory()->create());

    expect(fn (): Comment => comment($task, $stranger))->toThrow(CommentException::class);

    expect(Comment::query()->count())->toBe(0);
});

it('writes the morph alias rather than a class name', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    expect(comment($task, $actor)->commentable_type)->toBe('task');
});
