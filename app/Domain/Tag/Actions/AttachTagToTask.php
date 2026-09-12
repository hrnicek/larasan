<?php

declare(strict_types=1);

namespace App\Domain\Tag\Actions;

use App\Domain\Tag\Events\TaskTagged;
use App\Domain\Tag\Exceptions\TagException;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class AttachTagToTask
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, Tag $tag, User $actor): void
    {
        if ($actor->cannot('update', $task)) {
            throw TagException::cannotTagTask();
        }

        // Foreign keys cannot enforce that the tag and the task share a workspace. See ADR-0005.
        if ($tag->workspace_id !== $task->workspace_id) {
            throw TagException::tagIsFromAnotherWorkspace();
        }

        if ($task->tags()->whereKey($tag->id)->exists()) {
            return;
        }

        $task->tags()->attach($tag->id);

        $this->events->dispatch(new TaskTagged($task->id, $tag->id, $task->workspace_id, $actor->id));
    }
}
