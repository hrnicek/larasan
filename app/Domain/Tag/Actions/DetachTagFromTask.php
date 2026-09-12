<?php

declare(strict_types=1);

namespace App\Domain\Tag\Actions;

use App\Domain\Tag\Events\TaskUntagged;
use App\Domain\Tag\Exceptions\TagException;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class DetachTagFromTask
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, Tag $tag, User $actor): void
    {
        if ($actor->cannot('update', $task)) {
            throw TagException::cannotTagTask();
        }

        if ($task->tags()->detach($tag->id) === 0) {
            return;
        }

        $this->events->dispatch(new TaskUntagged($task->id, $tag->id, $task->workspace_id, $actor->id));
    }
}
