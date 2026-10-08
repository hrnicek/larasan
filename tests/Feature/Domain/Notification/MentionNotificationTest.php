<?php

declare(strict_types=1);

use App\Domain\Comment\Actions\CreateComment;
use App\Domain\Comment\Actions\DeleteComment;
use App\Domain\Comment\Actions\UpdateComment;
use App\Domain\Comment\Data\CreateCommentData;
use App\Domain\Comment\Data\UpdateCommentData;
use App\Domain\Comment\Events\CommentCreated;
use App\Domain\Comment\Models\Comment;
use App\Domain\Notification\Listeners\NotifyMentionedPeople;
use App\Domain\Notification\Notifications\CommentPostedNotification;
use App\Domain\Notification\Notifications\MentionedInCommentNotification;
use App\Domain\Notification\Queries\InboxQuery;
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

/**
 * @return list<string>
 */
function notificationTypesOf(User $user): array
{
    /** @var list<string> $types */
    $types = DB::table('notifications')
        ->where('notifiable_type', 'user')
        ->where('notifiable_id', $user->id)
        ->orderBy('created_at')
        ->pluck('type')
        ->all();

    return $types;
}

function mentionIn(Task $task, User $author, string $body): Comment
{
    return app(CreateComment::class)->handle($task, $author, new CreateCommentData(body: $body));
}

it('tells the person a comment names', function (): void {
    [$workspace, , $author] = placeableProject();
    $jana = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    $comment = mentionIn($task, $author, 'Over to '.mentionOf($jana));

    $row = DB::table('notifications')->where('notifiable_id', $jana->id)->sole(['type', 'workspace_id', 'data']);

    expect($row->type)->toBe(MentionedInCommentNotification::class)
        ->and($row->workspace_id)->toBe($workspace->id)
        ->and(json_decode((string) $row->data, true, 512, JSON_THROW_ON_ERROR))
        ->toBe(['comment_id' => $comment->id, 'task_id' => $task->id, 'author_id' => $author->id]);
});

it('never tells the author about naming themselves', function (): void {
    [$workspace, , $author] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    mentionIn($task, $author, 'Reminder for '.mentionOf($author));

    expect(notificationTypesOf($author))->toBe([]);
});

it('tells a named watcher once, by the mention, and the other watchers as before', function (): void {
    [$workspace, , $author] = placeableProject();
    $named = memberOf($workspace);
    $other = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();
    app(FollowTask::class)->handle($task, $named);
    app(FollowTask::class)->handle($task, $other);

    mentionIn($task, $author, 'Over to '.mentionOf($named));

    expect(notificationTypesOf($named))->toBe([MentionedInCommentNotification::class])
        ->and(notificationTypesOf($other))->toBe([CommentPostedNotification::class]);
});

it('tells somebody an edit adds, and nobody twice', function (): void {
    [$workspace, , $author] = placeableProject();
    $first = memberOf($workspace);
    $added = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();
    $comment = mentionIn($task, $author, 'Over to '.mentionOf($first));

    $edit = fn (string $body): Comment => app(UpdateComment::class)->handle($comment->refresh(), $author, new UpdateCommentData(body: $body));

    $edit('Over to '.mentionOf($first).' and '.mentionOf($added));
    $edit('Over to both of you, '.mentionOf($first).' and '.mentionOf($added));

    expect(notificationTypesOf($first))->toBe([MentionedInCommentNotification::class])
        ->and(notificationTypesOf($added))->toBe([MentionedInCommentNotification::class]);
});

it('says nothing to somebody edited out before the job ran', function (): void {
    [$workspace, , $author] = placeableProject();
    $jana = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();
    $comment = Comment::factory()->on($task)->by($author)->create(['body' => 'Nobody named any more']);

    app(NotifyMentionedPeople::class)->handle(
        new CommentCreated($comment->id, $workspace->id, 'task', $task->id, $author->id, [$jana->id]),
    );

    expect(notificationTypesOf($jana))->toBe([]);
});

it('says nothing about a comment removed before the job ran', function (): void {
    [$workspace, , $author] = placeableProject();
    $jana = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();
    $comment = Comment::factory()->on($task)->by($author)->create(['body' => 'Over to '.mentionOf($jana)]);
    app(DeleteComment::class)->handle($comment, $author);

    app(NotifyMentionedPeople::class)->handle(
        new CommentCreated($comment->id, $workspace->id, 'task', $task->id, $author->id, [$jana->id]),
    );

    expect(notificationTypesOf($jana))->toBe([]);
});

it('says nothing to somebody who lost the task before the job ran', function (): void {
    $workspace = Workspace::factory()->create();
    $author = memberOf($workspace, WorkspaceRole::Owner);
    $jana = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Workspace]);
    ProjectMembership::factory()->in($project)->forUser($author)->withAccess(ProjectAccessLevel::Owner)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();
    $comment = Comment::factory()->on($task)->by($author)->create(['body' => 'Over to '.mentionOf($jana)]);

    $project->forceFill(['visibility' => ProjectVisibility::Private])->save();

    app(NotifyMentionedPeople::class)->handle(
        new CommentCreated($comment->id, $workspace->id, 'task', $task->id, $author->id, [$jana->id]),
    );

    expect(notificationTypesOf($jana))->toBe([]);
});

it('reads as a mention in the Inbox of the person named', function (): void {
    [$workspace, , $author] = placeableProject();
    $jana = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create(['title' => 'Fix login']);

    mentionIn($task, $author, 'Over to '.mentionOf($jana));

    $row = app(InboxQuery::class)($workspace, $jana)['notifications'][0];

    expect($row['type'])->toBe('comment.mentioned')
        ->and($row['actor']['id'])->toBe($author->id)
        ->and($row['subject']['title'])->toBe('Fix login');
});
