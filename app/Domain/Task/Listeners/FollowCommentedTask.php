<?php

declare(strict_types=1);

namespace App\Domain\Task\Listeners;

use App\Domain\Comment\Events\CommentCreated;
use App\Domain\Task\Actions\FollowTask;
use App\Domain\Task\Models\Task;
use App\Models\User;

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
