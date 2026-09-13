<?php

declare(strict_types=1);

namespace App\Domain\Placement\Queries;

use App\Domain\Task\Ancestry\ParentChain;
use Illuminate\Support\Facades\DB;

final readonly class ChannelsForTask
{
    /**
     * The task and its unplaced ancestors, up to and including the first placed one. Trashed rows are
     * walked too, so a task deleted a moment ago is still announced where it was seen.
     */
    private const string GOVERNING_CHAIN = <<<'SQL'
        (
            with recursive chain (id, parent_id, workspace_id, depth) as (
                select task.id, task.parent_id, task.workspace_id, 0
                from tasks as task
                where task.id = ? and task.workspace_id = ?
                union all
                select parent.id, parent.parent_id, parent.workspace_id, chain.depth + 1
                from chain
                join tasks as parent on parent.id = chain.parent_id and parent.workspace_id = chain.workspace_id
                where chain.depth < ?
                    and not exists (select 1 from task_project_memberships as placement where placement.task_id = chain.id)
            )
            select id, parent_id, depth from chain
        ) as chain
        SQL;

    /**
     * @return list<string>
     */
    public function __invoke(string $taskId, string $workspaceId): array
    {
        $rows = DB::query()
            ->fromRaw(self::GOVERNING_CHAIN, [$taskId, $workspaceId, ParentChain::MAX_DEPTH])
            ->leftJoin('task_project_memberships as placement', 'placement.task_id', '=', 'chain.id')
            ->orderByDesc('chain.depth')
            ->orderBy('placement.project_id')
            ->get(['chain.depth', 'chain.parent_id', 'placement.project_id']);

        $governing = $rows->where('depth', $rows->max('depth'));

        $projectIds = array_values(array_filter($governing->pluck('project_id')->all(), is_string(...)));

        // A placed task is never announced on the workspace channel, or a private project's task would leak to every member.
        if ($projectIds !== []) {
            return array_map(static fn (string $projectId): string => "project.{$projectId}", $projectIds);
        }

        return $governing->contains(fn (object $row): bool => $row->parent_id === null)
            ? ["workspace.{$workspaceId}"]
            : [];
    }
}
