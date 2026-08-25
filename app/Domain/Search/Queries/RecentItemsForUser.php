<?php

declare(strict_types=1);

namespace App\Domain\Search\Queries;

use App\Domain\Project\Models\Project;
use App\Domain\Search\Models\RecentItem;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Queries\ReachableTasks;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What this person had open lately, in this workspace, and may still open.
 *
 * The reach rules are read here rather than trusted from the row: access is taken away between
 * one visit and the next, and a list of things somebody used to be allowed to see is the same
 * leak a stale search index would be (ADR-0016). Two queries, one per kind, rather than a morph
 * join — each kind's rule is its own.
 */
final readonly class RecentItemsForUser
{
    public const SHOWN = 8;

    public function __construct(private ReachableTasks $reachable) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(Workspace $workspace, User $actor, int $limit = self::SHOWN): array
    {
        $items = RecentItem::query()
            ->where('user_id', $actor->id)
            ->where('workspace_id', $workspace->id)
            ->latest('opened_at')
            ->orderByDesc('id')
            // More than are shown, because what the rules take out comes out after the read.
            ->limit($limit * 3)
            ->get();

        if ($items->isEmpty()) {
            return [];
        }

        $tasks = $this->tasks($workspace, $actor, $items);
        $projects = $this->projects($workspace, $actor, $items);

        $rows = $items
            ->map(fn (RecentItem $item): ?array => match ($item->subject_type) {
                'task' => $tasks[$item->subject_id] ?? null,
                'project' => $projects[$item->subject_id] ?? null,
                default => null,
            })
            ->filter()
            ->take($limit);

        return array_values($rows->all());
    }

    /**
     * @param  Collection<int, RecentItem>  $items
     * @return array<string, array<string, mixed>>
     */
    private function tasks(Workspace $workspace, User $actor, Collection $items): array
    {
        $ids = $items->where('subject_type', 'task')->pluck('subject_id');

        if ($ids->isEmpty()) {
            return [];
        }

        return $this->reachable
            ->constrain(Task::query(), $workspace, $actor)
            ->whereIn('tasks.id', $ids)
            ->get(['id', 'title', 'completed_at'])
            ->mapWithKeys(fn (Task $task): array => [$task->id => [
                'kind' => 'tasks',
                'id' => $task->id,
                'title' => $task->title,
                'completed' => $task->completed_at !== null,
            ]])
            ->all();
    }

    /**
     * @param  Collection<int, RecentItem>  $items
     * @return array<string, array<string, mixed>>
     */
    private function projects(Workspace $workspace, User $actor, Collection $items): array
    {
        $ids = $items->where('subject_type', 'project')->pluck('subject_id');

        if ($ids->isEmpty()) {
            return [];
        }

        return $this->reachable
            ->projectIds($workspace, $actor)
            ->whereIn('projects.id', $ids)
            ->reorder()
            // `projectIds()` is a subquery of keys, so its own `select` has to be replaced
            // rather than added to: `get($columns)` is ignored once a query names its columns.
            ->select(['projects.id', 'projects.name', 'projects.color', 'projects.icon', 'projects.archived_at'])
            ->get()
            ->mapWithKeys(fn (Project $project): array => [$project->id => [
                'kind' => 'projects',
                'id' => $project->id,
                'title' => $project->name,
                'color' => $project->color?->value,
                'icon' => $project->icon?->value,
                'archived' => $project->archived_at !== null,
            ]])
            ->all();
    }
}
