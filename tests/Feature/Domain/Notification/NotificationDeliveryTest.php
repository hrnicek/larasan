<?php

declare(strict_types=1);

use App\Domain\Comment\Actions\CreateComment;
use App\Domain\Comment\Data\CreateCommentData;
use App\Domain\Notification\Notifications\CommentPostedNotification;
use App\Domain\Notification\Notifications\TaskAssignedNotification;
use App\Domain\Task\Actions\AssignTask;
use App\Domain\Task\Actions\FollowTask;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * @return list<object{type: string, workspace_id: string, data: string}>
 */
function inboxOf(User $user): array
{
    $rows = DB::table('notifications')
        ->where('notifiable_type', 'user')
        ->where('notifiable_id', $user->id)
        ->orderBy('created_at')
        ->get(['type', 'workspace_id', 'data']);

    /** @var list<object{type: string, workspace_id: string, data: string}> $inbox */
    $inbox = array_values($rows->all());

    return $inbox;
}

it('tells somebody a task was given to them', function (): void {
    [$workspace, , $actor] = placeableProject();
    $assignee = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    app(AssignTask::class)->handle($task, $actor, $assignee);

    $inbox = inboxOf($assignee);

    expect($inbox)->toHaveCount(1)
        ->and($inbox[0]->type)->toBe(TaskAssignedNotification::class)
        // The column the framework's own table does not have, without which the Inbox cannot
        // place the row and the badge cannot count it.
        ->and($inbox[0]->workspace_id)->toBe($workspace->id)
        ->and(json_decode((string) $inbox[0]->data, true, 512, JSON_THROW_ON_ERROR))
        ->toBe(['task_id' => $task->id, 'assigned_by_id' => $actor->id]);
});

it('says nothing when somebody assigns a task to themselves', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    app(AssignTask::class)->handle($task, $actor, $actor);

    // An inbox full of one's own doing is an inbox nobody reads.
    expect(inboxOf($actor))->toBeEmpty();
});

it('says nothing when a task is taken back from somebody', function (): void {
    [$workspace, , $actor] = placeableProject();
    $assignee = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    app(AssignTask::class)->handle($task, $actor, $assignee);
    app(AssignTask::class)->handle($task, $actor, null);

    // There is nobody to tell: unassignment has no recipient, and the person it was taken from
    // finds that out from the task rather than from a second notification.
    expect(inboxOf($assignee))->toHaveCount(1);
});

it('tells the people watching a task that somebody commented', function (): void {
    [$workspace, , $author] = placeableProject();
    $watcher = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();
    app(FollowTask::class)->handle($task, $watcher);

    $comment = app(CreateComment::class)->handle($task, $author, new CreateCommentData(body: 'Looks right to me'));

    $inbox = inboxOf($watcher);

    expect($inbox)->toHaveCount(1)
        ->and($inbox[0]->type)->toBe(CommentPostedNotification::class)
        ->and($inbox[0]->workspace_id)->toBe($workspace->id)
        ->and(json_decode((string) $inbox[0]->data, true, 512, JSON_THROW_ON_ERROR))
        ->toBe(['comment_id' => $comment->id, 'task_id' => $task->id, 'author_id' => $author->id]);
});

it('never tells somebody about their own comment', function (): void {
    [$workspace, , $author] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    app(FollowTask::class)->handle($task, $author);

    app(CreateComment::class)->handle($task, $author, new CreateCommentData(body: 'Looks right to me'));

    // Being told about your own comment is the fastest way to teach somebody to ignore the
    // inbox entirely.
    expect(inboxOf($author))->toBeEmpty();
});

it('has nobody to tell when nobody is watching', function (): void {
    [$workspace, , $author] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    app(CreateComment::class)->handle($task, $author, new CreateCommentData(body: 'Looks right to me'));

    expect(DB::table('notifications')->count())->toBe(0);
});

it('writes the morph alias rather than a class name', function (): void {
    [$workspace, , $actor] = placeableProject();
    $assignee = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    app(AssignTask::class)->handle($task, $actor, $assignee);

    // A class name in a database column is a rename waiting to break a table, and the map is
    // enforced so an unmapped model is an error rather than a row nobody can read back.
    expect(DB::table('notifications')->value('notifiable_type'))->toBe('user');
});

it('refuses to write a database notification that cannot say which workspace it is from', function (): void {
    $user = User::factory()->create();

    // The alternative is a row the Inbox cannot place and the badge cannot count, discovered
    // long after whoever added the notification has moved on.
    expect(fn () => $user->notify(new class extends Notification
    {
        /** @return list<string> */
        public function via(mixed $notifiable): array
        {
            return ['database'];
        }

        /** @return array<string, mixed> */
        public function toArray(mixed $notifiable): array
        {
            return [];
        }
    }))->toThrow(LogicException::class);

    expect(DB::table('notifications')->count())->toBe(0);
});
