<?php

declare(strict_types=1);

use App\Domain\Comment\Actions\CreateComment;
use App\Domain\Comment\Data\CreateCommentData;
use App\Domain\Task\Actions\AssignTask;
use App\Domain\Task\Actions\UnfollowTask;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * @return list<int>
 */
function notifiedIds(): array
{
    /** @var list<int> $ids */
    $ids = DB::table('notifications')->orderBy('notifiable_id')->pluck('notifiable_id')->all();

    return $ids;
}

it('tells somebody who joined the conversation about the next comment', function (): void {
    [$workspace, , $first] = placeableProject();
    $second = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    app(CreateComment::class)->handle($task, $first, new CreateCommentData(body: 'What about this?'));

    app(CreateComment::class)->handle($task, $second, new CreateCommentData(body: 'Good point'));

    expect(notifiedIds())->toBe([$first->id]);
});

it('tells an assignee about a comment without anybody subscribing them by hand', function (): void {
    [$workspace, , $actor] = placeableProject();
    $assignee = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    app(AssignTask::class)->handle($task, $actor, $assignee);
    DB::table('notifications')->delete();

    app(CreateComment::class)->handle($task, $actor, new CreateCommentData(body: 'Started on this'));

    expect(notifiedIds())->toBe([$assignee->id]);
});

it('tells nobody twice for being both the assignee and a follower', function (): void {
    [$workspace, , $actor] = placeableProject();
    $assignee = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    app(AssignTask::class)->handle($task, $actor, $assignee);
    app(CreateComment::class)->handle($task, $assignee, new CreateCommentData(body: 'Mine'));
    DB::table('notifications')->delete();

    app(CreateComment::class)->handle($task, $actor, new CreateCommentData(body: 'Any progress?'));

    expect(notifiedIds())->toBe([$assignee->id]);
});

it('never tells the author about their own comment, even though they now follow it', function (): void {
    [$workspace, , $author] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    app(CreateComment::class)->handle($task, $author, new CreateCommentData(body: 'Thinking out loud'));

    // The follow listener runs first, so the author is already a follower when the notifier runs.
    expect(notifiedIds())->toBe([])
        ->and($task->followers()->count())->toBe(1);
});

it('stops telling somebody who stopped watching', function (): void {
    [$workspace, , $first] = placeableProject();
    $second = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    app(CreateComment::class)->handle($task, $first, new CreateCommentData(body: 'First'));
    app(UnfollowTask::class)->handle($task, $first);
    DB::table('notifications')->delete();

    app(CreateComment::class)->handle($task, $second, new CreateCommentData(body: 'Second'));

    expect(notifiedIds())->toBe([]);
});

it('keeps another workspace out of it', function (): void {
    [$workspace, , $author] = placeableProject();
    $stranger = memberOf(Workspace::factory()->create());
    $task = Task::factory()->in($workspace)->create();

    app(CreateComment::class)->handle($task, $author, new CreateCommentData(body: 'Ours'));

    expect(User::query()->whereKey($stranger->id)->exists())->toBeTrue()
        ->and(DB::table('notifications')->where('notifiable_id', $stranger->id)->count())->toBe(0);
});
