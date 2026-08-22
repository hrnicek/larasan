<?php

declare(strict_types=1);

namespace App\Domain\Project\Queries;

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection as Grouped;

/**
 * What a project holds, grouped by column and in order: the list view's whole data source.
 *
 * Three rules the earlier phases put here rather than in the screen:
 *
 * - the ungrouped bucket is a bucket. A card in the project and in no column is not missing
 *   (ADR-0004), and a list that quietly dropped it would lose work rather than misplace it.
 * - a column's count and a column's rows come from the same scope. `visible()` excludes the
 *   placements of soft-deleted tasks, and a header counted any other way disagrees with what
 *   is drawn the first time somebody deletes a task (TASK-050-013).
 * - authorization is computed once, for the project. Asking the placement policy per card
 *   throws outside production and is an N+1 inside it (TASK-070-015), and the answer is the
 *   same for every card on the board anyway.
 */
final readonly class ProjectListQuery
{
    /**
     * @return array{
     *     sections: list<array{id: string|null, name: string|null, color: string|null, count: int, tasks: list<array<string, mixed>>}>,
     *     can: array{createTask: bool, updateTask: bool, deleteTask: bool},
     * }
     */
    public function __invoke(Project $project, User $actor): array
    {
        $cards = $this->cards($project);

        $sections = $project->sections()->get()
            ->map(fn (Section $section): array => $this->group(
                $section->id,
                $section->name,
                $section->color?->value,
                $cards->get($section->id) ?? new Collection,
            ))
            ->all();

        $ungrouped = $cards->get('') ?? new Collection;

        // Only when it holds something. An empty "no column" group on every board is noise;
        // a non-empty one is work somebody has to be able to see.
        if ($ungrouped->isNotEmpty()) {
            $sections[] = $this->group(null, null, null, $ungrouped);
        }

        return [
            'sections' => array_values($sections),
            /*
             * The permissions the screen renders, answered by the server. Three different
             * questions, not one: creating a task and placing it here is `createTask`,
             * editing a card's task is `task.update`, and removing one is `task.delete` —
             * a role can hold any of them without the others (ADR-0010).
             */
            'can' => [
                'createTask' => $actor->can('createTask', $project),
                'updateTask' => $project->allowsChangesBy($actor, Capability::TaskUpdate),
                'deleteTask' => $project->allowsChangesBy($actor, Capability::TaskDelete),
            ],
        ];
    }

    /**
     * Every visible card in the project, in position order, keyed by section — one query for
     * the placements and one for their tasks, whatever the board's size.
     *
     * @return Grouped<string, Collection<int, TaskProjectMembership>>
     */
    private function cards(Project $project): Grouped
    {
        return TaskProjectMembership::query()
            ->visible()
            ->where('project_id', $project->id)
            ->with(['task' => function (Relation $tasks): void {
                $tasks
                    ->select(['id', 'workspace_id', 'title', 'completed_at', 'due_at', 'priority', 'assignee_id'])
                    ->with('assignee:id,name,email');
            }])
            ->orderBy('position')
            ->get()
            ->groupBy(fn (TaskProjectMembership $card): string => $card->section_id ?? '');
    }

    /**
     * @param  Collection<int, TaskProjectMembership>  $cards
     * @return array{id: string|null, name: string|null, color: string|null, count: int, tasks: list<array<string, mixed>>}
     */
    private function group(?string $id, ?string $name, ?string $color, Collection $cards): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'color' => $color,
            // The same collection the rows come from, so the header cannot disagree with them.
            'count' => $cards->count(),
            'tasks' => array_values($cards->map(fn (TaskProjectMembership $card): array => $this->card($card))->all()),
        ];
    }

    /**
     * Listed column by column rather than handed the model: a model would ship every column
     * the table grows later as a public API by accident.
     *
     * @return array<string, mixed>
     */
    private function card(TaskProjectMembership $card): array
    {
        /** @var Task $task */
        $task = $card->task;
        $assignee = $task->assignee;

        return [
            'placementId' => $card->id,
            'id' => $task->id,
            'title' => $task->title,
            'completedAt' => $task->completed_at?->toIso8601String(),
            'dueAt' => $task->due_at?->toIso8601String(),
            'priority' => $task->priority->value,
            'assignee' => $assignee === null ? null : [
                'id' => $assignee->id,
                'name' => $assignee->name,
                'email' => $assignee->email,
                'avatar' => null,
            ],
        ];
    }
}
