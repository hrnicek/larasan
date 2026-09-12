<?php

declare(strict_types=1);

namespace App\Domain\Placement\Queries;

use App\Domain\Placement\Models\TaskProjectMembership;

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

        // A placed task is never announced on the workspace channel, or a private project's task would leak to every member.
        if ($projectIds === []) {
            return ["workspace.{$workspaceId}"];
        }

        return array_map(static fn (string $projectId): string => "project.{$projectId}", $projectIds);
    }
}
