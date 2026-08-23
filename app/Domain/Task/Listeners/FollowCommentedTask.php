<?php

declare(strict_types=1);

namespace App\Domain\Task\Listeners;

use App\Domain\Comment\Events\CommentCreated;
use App\Domain\Task\Actions\FollowTask;
use App\Domain\Task\Models\Task;
use App\Models\User;

/**
 * Saying something about a task means watching it.
 *
 * Somebody who joins a conversation and then hears none of the replies has been given the worst
 * of both: they are part of it and cannot follow it.
 *
 * This does re-add somebody who had stopped watching and then commented again. Unfollowing is
 * "not now" rather than "never again", and speaking is the clearest possible statement of
 * interest.
 */
final readonly class FollowCommentedTask
{
    public function __construct(private FollowTask $follow) {}

    public function handle(CommentCreated $event): void
    {
        if ($event->subjectType !== 'task') {
            return;
        }

        $task = Task::query()->find($event->subjectId);
        $author = User::query()->find($event->authorId);

        if ($task === null || $author === null) {
            return;
        }

        $this->follow->handle($task, $author);
    }
}
