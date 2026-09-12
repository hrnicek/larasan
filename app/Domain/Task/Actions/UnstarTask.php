<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskStar;
use App\Models\User;

final readonly class UnstarTask
{
    public function handle(Task $task, User $actor): void
    {
        $star = $task->stars()->where('user_id', $actor->id)->first();

        if (! $star instanceof TaskStar) {
            return;
        }

        $star->delete();
    }
}
