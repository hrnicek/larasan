<?php

declare(strict_types=1);

namespace App\Domain\Placement\Queries;

use App\Domain\Placement\Models\TaskProjectMembership;

/**
 * Which channels may hear that a task changed.
 *
 * **Placement decides**, which is the whole security content of task broadcasting: a task
 * that sits in projects is announced on those projects' channels and nowhere else, because
 * a private project's task on the workspace channel would tell every member that the task
 * exists — the thing the private project was for. A task in no project is announced on the
 * workspace channel, where the people who can see loose tasks are.
 *
 * It lives in the placement context because that is what it reads and what it means: the
 * question "where does this task appear" is the same question the board asks.
 */
final readonly class ChannelsForTask
{
    /**
     * @return list<string>
     */
    public function __invoke(string $taskId, string $workspaceId): array
    {
        /** @var list<string> $projectIds */
        $projectIds = TaskProjectMembership::query()
            ->where('task_id', $taskId)
            ->orderBy('project_id')
            ->pluck('project_id')
            ->all();

        if ($projectIds === []) {
            return ["workspace.{$workspaceId}"];
        }

        return array_map(static fn (string $projectId): string => "project.{$projectId}", $projectIds);
    }
}
