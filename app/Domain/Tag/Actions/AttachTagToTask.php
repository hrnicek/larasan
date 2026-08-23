<?php

declare(strict_types=1);

namespace App\Domain\Tag\Actions;

use App\Domain\Tag\Events\TaskTagged;
use App\Domain\Tag\Exceptions\TagException;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Say what a task is about.
 *
 * Tagging a task is **editing** it, so the question is `update` on the task — reach and the
 * `task.update` capability, answered once by `TaskPolicy`. `tag.manage` is a different
 * permission for a different thing: making and renaming the workspace's vocabulary
 * (TASK-140-004).
 */
final readonly class AttachTagToTask
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, Tag $tag, User $actor): void
    {
        if ($actor->cannot('update', $task)) {
            throw TagException::cannotTagTask();
        }

        /*
         * Two valid ids that must not be combined. The foreign keys prove each row exists; only
         * this check proves they belong to the same tenant, and it lives here because the
         * schema cannot express it (ADR-0005).
         */
        if ($tag->workspace_id !== $task->workspace_id) {
            throw TagException::tagIsFromAnotherWorkspace();
        }

        // Attaching twice is the same tag, not two of them — and not an event either, because
        // nothing changed.
        if ($task->tags()->whereKey($tag->id)->exists()) {
            return;
        }

        $task->tags()->attach($tag->id);

        $this->events->dispatch(new TaskTagged($task->id, $tag->id, $task->workspace_id, $actor->id));
    }
}
