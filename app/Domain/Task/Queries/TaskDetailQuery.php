<?php

declare(strict_types=1);

namespace App\Domain\Task\Queries;

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * One task, everything its screen draws in one read.
 *
 * What is deliberately absent is as much of the design as what is here. Comments, activity and
 * attachments are deferred regions with tables that do not exist yet (TASK-100-011); followers
 * arrive with `task_followers` in TASK-100-010; tags are Phase 140 and custom fields Phase 150.
 * A query that pretended to know about them would be a query somebody has to unpick later.
 *
 * Reach is the policy's answer, not this query's: `TaskPolicy::view()` decides whether the
 * actor may see the task at all (TASK-070-017), and this decides what they see of it. The
 * permissions come back as flags, answered once for the task rather than per field.
 */
final readonly class TaskDetailQuery
{
    /**
     * @return array{
     *     task: array<string, mixed>,
     *     placements: list<array<string, mixed>>,
     *     subtasks: list<array<string, mixed>>,
     *     can: array{update: bool, delete: bool, comment: bool},
     * }
     */
    public function __invoke(Task $task, User $actor): array
    {
        $task->loadMissing([
            'assignee:id,name,email',
            'creator:id,name,email',
            'parent:id,title',
            'children' => fn (Relation $subtasks) => $subtasks->select(['id', 'parent_id', 'title', 'completed_at']),
            /*
             * Only the projects the actor can reach. A task can appear in a project they were
             * never given, and listing it here would leak a project name through a task they
             * are allowed to read (ADR-0006).
             */
            'placements' => fn (Relation $placements) => $placements->with([
                // `workspace` because visibility is answered by the project against the
                // workspace membership, and `workspace_id` because a select that omits a
                // relation's key breaks the relation rather than the query.
                'project:id,workspace_id,name,color,visibility,archived_at',
                'project.workspace',
                'section:id,name',
            ]),
        ]);

        return [
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'priority' => $task->priority->value,
                'dueAt' => $task->due_at?->toIso8601String(),
                'completedAt' => $task->completed_at?->toIso8601String(),
                'parent' => $task->parent === null ? null : [
                    'id' => $task->parent->id,
                    'title' => $task->parent->title,
                ],
                'assignee' => $this->person($task->assignee),
                'creator' => $this->person($task->creator),
            ],
            'placements' => $this->placements($task, $actor),
            'subtasks' => array_values($task->children
                ->map(fn (Task $subtask): array => [
                    'id' => $subtask->id,
                    'title' => $subtask->title,
                    'completedAt' => $subtask->completed_at?->toIso8601String(),
                ])
                ->all()),
            'can' => [
                'update' => $actor->can('update', $task),
                'delete' => $actor->can('delete', $task),
                // Commenting is Phase 110's operation; the flag is here because the screen
                // that hides the form is built now, and a flag added later is a form somebody
                // forgets to hide.
                'comment' => $task->workspace->membershipFor($actor)?->allows(Capability::CommentCreate) === true,
            ],
        ];
    }

    /**
     * The projects this task appears in — each with the column it sits in, and whether the
     * actor may still change what that project holds.
     *
     * @return list<array<string, mixed>>
     */
    private function placements(Task $task, User $actor): array
    {
        $rows = $task->placements
            ->filter(fn (TaskProjectMembership $placement): bool => $placement->project->isVisibleTo($actor))
            ->map(fn (TaskProjectMembership $placement): array => [
                'placementId' => $placement->id,
                'project' => [
                    'id' => $placement->project->id,
                    'name' => $placement->project->name,
                    'color' => $placement->project->color?->value,
                    'archived' => $placement->project->isArchived(),
                ],
                'section' => $placement->section === null ? null : [
                    'id' => $placement->section->id,
                    'name' => $placement->section->name,
                ],
                'canDetach' => $placement->project->allowsChangesBy($actor, Capability::TaskUpdate),
            ])
            ->all();

        return array_values($rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function person(?User $user): ?array
    {
        return $user === null ? null : [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => null,
        ];
    }
}
