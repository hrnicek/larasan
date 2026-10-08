<?php

declare(strict_types=1);

use App\Domain\Comment\Actions\CreateComment;
use App\Domain\Comment\Data\CreateCommentData;
use App\Domain\Notification\Listeners\NotifyAssignee;
use App\Domain\Notification\Notifications\CommentPostedNotification;
use App\Domain\Notification\Notifications\TaskAssignedNotification;
use App\Domain\Task\Actions\AssignTask;
use App\Domain\Task\Actions\FollowTask;
use App\Domain\Task\Events\TaskAssigned;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
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
        ->and($inbox[0]->workspace_id)->toBe($workspace->id)
        ->and(json_decode((string) $inbox[0]->data, true, 512, JSON_THROW_ON_ERROR))
        ->toBe(['task_id' => $task->id, 'assigned_by_id' => $actor->id]);
});

it('says nothing when somebody assigns a task to themselves', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    app(AssignTask::class)->handle($task, $actor, $actor);

    expect(inboxOf($actor))->toBeEmpty();
});

it('says nothing when a task is taken back from somebody', function (): void {
    [$workspace, , $actor] = placeableProject();
    $assignee = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    app(AssignTask::class)->handle($task, $actor, $assignee);
    app(AssignTask::class)->handle($task, $actor, null);

    expect(inboxOf($assignee))->toHaveCount(1);
});

it('says nothing when the task was given to somebody else before the notice went out', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $assignee = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create(['assignee_id' => $actor->id]);

    app(NotifyAssignee::class)->handle(new TaskAssigned($task->id, $workspace->id, $assignee->id, $actor->id));

    expect(inboxOf($assignee))->toBeEmpty();
});

it('says nothing when the task was deleted before the notice went out', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $assignee = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create(['assignee_id' => $assignee->id]);
    $task->delete();

    app(NotifyAssignee::class)->handle(new TaskAssigned($task->id, $workspace->id, $assignee->id, $actor->id));

    expect(inboxOf($assignee))->toBeEmpty();
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

    expect(DB::table('notifications')->value('notifiable_type'))->toBe('user');
});

it('refuses to write a database notification that cannot say which workspace it is from', function (): void {
    $user = User::factory()->create();

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
