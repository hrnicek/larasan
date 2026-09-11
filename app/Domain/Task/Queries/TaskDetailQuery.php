<?php

declare(strict_types=1);

namespace App\Domain\Task\Queries;

use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\CustomFieldOption;
use App\Domain\CustomField\Models\TaskCustomFieldValue;
use App\Domain\File\Models\Attachment;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\CustomFieldType;
use App\Domain\Shared\Enums\FileKind;
use App\Domain\Shared\Payloads\PersonSummary;
use App\Domain\Tag\Models\Tag;
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
    public function __construct(private ReachableTasks $reachable) {}

    /**
     * @return array{
     *     task: array<string, mixed>,
     *     placements: list<array<string, mixed>>,
     *     availableProjects: list<array{id: string, name: string}>,
     *     customFields: list<array<string, mixed>>,
     *     tags: list<array<string, mixed>>,
     *     availableTags: list<array<string, mixed>>,
     *     attachments: list<array<string, mixed>>,
     *     subtasks: list<array<string, mixed>>,
     *     followers: list<array<string, mixed>>,
     *     following: bool,
     *     starred: bool,
     *     can: array{update: bool, delete: bool, comment: bool, attach: bool, manageTags: bool},
     * }
     */
    public function __invoke(Task $task, User $actor): array
    {
        $task->loadMissing([
            PersonSummary::eager('assignee'),
            PersonSummary::eager('creator'),
            /*
             * Constrained rather than checked afterwards, the same way the subtasks below are:
             * a parent inside a project the reader was never given is a title they were never
             * given either, and asking the gate about it after loading it is two more queries
             * for an answer the eager load can carry (TASK-260-002).
             */
            'parent' => fn (Relation $parent) => $parent
                ->whereIn('tasks.id', $this->reachable->idsFor($task->workspace, $actor))
                ->select(['tasks.id', 'tasks.title']),
            /*
             * Constrained the way the placements below are, and for the same reason. A subtask
             * is a task: one filed in a project the reader was never given is work they may
             * not see, and naming it under a task they *may* see is the leak the placement
             * list was already written to avoid (TASK-260-002).
             */
            'children' => fn (Relation $subtasks) => $subtasks
                ->whereIn('tasks.id', $this->reachable->idsFor($task->workspace, $actor))
                ->select(['tasks.id', 'tasks.parent_id', 'tasks.title', 'tasks.completed_at']),
            PersonSummary::eager('followers'),
            // The file behind each attachment and the person who uploaded it: a list of
            // documents is one query, not one per row.
            PersonSummary::eager('attachments.file.uploader'),
            'tags:id,name,color',
            'customFieldValues',
            // The fields this task's projects show, with their choices: one read for the page
            // rather than one per field.
            'placements.project.customFields.options',
            /*
             * Only the projects the actor can reach. A task can appear in a project they were
             * never given, and listing it here would leak a project name through a task they
             * are allowed to read (ADR-0006).
             */
            'placements' => fn (Relation $placements) => $placements->with([
                // `workspace` because visibility is answered by the project against the
                // workspace membership, and `workspace_id` because a select that omits a
                // relation's key breaks the relation rather than the query.
                // `visibility`, `default_access_level` and `archived_at` are not drawn — they
                // are what `Project::allowsChangesBy()` reads for the per-placement `canChange`
                // below, and strict Eloquent throws on an attribute a select left out.
                'project:id,workspace_id,name,color,visibility,default_access_level,archived_at',
                'project.workspace',
                // The columns each project offers, so the panel can move the task between them
                // without a second request per project.
                'project.sections:id,project_id,name,position',
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
                // Null where the eager load above left it out, which is where the reader may
                // not open it.
                'parent' => $task->parent === null ? null : [
                    'id' => $task->parent->id,
                    'title' => $task->parent->title,
                ],
                'assignee' => PersonSummary::fromNullable($task->assignee),
                'creator' => PersonSummary::fromNullable($task->creator),
            ],
            'customFields' => $this->customFields($task, $actor),
            'tags' => array_values($task->tags
                ->map(fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color?->value,
                ])
                ->all()),
            /*
             * The workspace's whole vocabulary, so the picker can offer it without a second
             * request. A workspace has tens of tags, not thousands — this is the one list on the
             * screen small enough to send whole.
             */
            'availableTags' => array_values($task->workspace->tags()
                ->orderBy('name')
                ->get(['id', 'name', 'color'])
                ->map(fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color?->value,
                ])
                ->all()),
            'attachments' => $this->attachments($task, $actor),
            'placements' => $this->placements($task, $actor),
            'availableProjects' => $this->availableProjects($task, $actor),
            'subtasks' => array_values($task->children
                ->map(fn (Task $subtask): array => [
                    'id' => $subtask->id,
                    'title' => $subtask->title,
                    'completedAt' => $subtask->completed_at?->toIso8601String(),
                ])
                ->all()),
            'followers' => array_values($task->followers
                ->map(PersonSummary::from(...))
                ->all()),
            // Whether the actor is one of them, so the control knows which way it points
            // without the client comparing ids the server already compared.
            'following' => $task->followers->contains('id', $actor->id),
            /*
             * This reader's own star, which is not a fact about the task: two people opening the
             * same panel get two different answers, and neither is told about the other's.
             */
            'starred' => $task->stars()->where('user_id', $actor->id)->exists(),
            'can' => [
                'update' => $actor->can('update', $task),
                'delete' => $actor->can('delete', $task),
                /*
                 * Asked of the policy, not of the capability. The capability is one third of
                 * the answer — reach and the board's own access level are the rest — and a
                 * flag that renders a form the endpoint behind it refuses is worse than no
                 * flag at all (TASK-260-001).
                 */
                'comment' => $actor->can('comment', $task),
                'attach' => $actor->can('attach', $task),
                /*
                 * A capability rather than a policy, because inventing a word is a decision
                 * about the workspace and not about this task: whoever may edit the task may
                 * label it, and only this says whether the picker may offer to make one.
                 */
                'manageTags' => $actor->can(Capability::TagManage->value, $task->workspace),
            ],
        ];
    }

    /**
     * The fields this task's projects show, each with this task's answer.
     *
     * Taken from the placements the reader can see, so a field only a private project shows is
     * not named to somebody who cannot open that project — the rule the placement list follows.
     * A field on two of the task's projects is one field here, not two.
     *
     * @return list<array<string, mixed>>
     */
    private function customFields(Task $task, User $actor): array
    {
        $answers = $task->customFieldValues->keyBy('custom_field_id');

        $fields = $task->placements
            ->filter(fn (TaskProjectMembership $placement): bool => $placement->project->isVisibleTo($actor))
            ->flatMap(fn (TaskProjectMembership $placement) => $placement->project->customFields)
            ->unique('id')
            ->values();

        return array_values($fields
            ->map(function (CustomField $field) use ($answers): array {
                $answer = $answers->get($field->id);

                return [
                    'id' => $field->id,
                    'name' => $field->name,
                    'type' => $field->type->value,
                    // Only a choice field has any, and an empty list on the others keeps the
                    // shape one shape.
                    'options' => array_values($field->options
                        ->map(fn (CustomFieldOption $option): array => [
                            'id' => $option->id,
                            'label' => $option->label,
                            'color' => $option->color?->value,
                        ])
                        ->all()),
                    'value' => $this->answer($field, $answer),
                ];
            })
            ->all());
    }

    /**
     * The answer as the screen wants it, decided by the **field's type** rather than by what PHP
     * happens to hand back: a decimal column reads as a string, and sending `"12.500000"` where
     * the client expects a number is the kind of thing that only shows up in somebody's sort
     * order.
     */
    private function answer(CustomField $field, ?TaskCustomFieldValue $answer): string|float|bool|null
    {
        if ($answer === null || $answer->value($field) === null) {
            return null;
        }

        // Each branch reads its own column, which is what "the type decides where the value
        // lives" means when it is written down rather than described.
        return match ($field->type) {
            CustomFieldType::Number => (float) $answer->value_number,
            CustomFieldType::Boolean => (bool) $answer->value_boolean,
            CustomFieldType::Date => $answer->value_date?->toDateString(),
            CustomFieldType::Text,
            CustomFieldType::Email,
            CustomFieldType::Phone,
            CustomFieldType::Link => (string) $answer->value_text,
            CustomFieldType::Select => (string) $answer->value_option_id,
        };
    }

    /**
     * What is attached to this task.
     *
     * `canDelete` is answered here rather than in the template, and answered the way
     * `AttachmentPolicy` answers it — reach is already settled for anybody reading this task, so
     * what is left is authorship of the upload and one capability, asked once for the page.
     *
     * The stored path is not sent. It is generated, it is nobody's business outside its table,
     * and a download goes through the endpoint that asks a question first (ADR-0007).
     *
     * @return list<array<string, mixed>>
     */
    private function attachments(Task $task, User $actor): array
    {
        $canModerate = $task->workspace->membershipFor($actor)?->allows(Capability::FileDelete) === true;

        return array_values($task->attachments
            ->map(function (Attachment $attachment) use ($actor, $canModerate): array {
                $file = $attachment->file;

                return [
                    'id' => $attachment->id,
                    'name' => $file->original_name,
                    'size' => $file->size,
                    'mimeType' => $file->mime_type,
                    /*
                     * Read here rather than in the template. Whether a row draws as a thumbnail
                     * or as a filename is the same question the files view asks, and asking it
                     * twice in two vocabularies is how the two views start disagreeing.
                     */
                    'kind' => FileKind::fromMime($file->mime_type, $file->extension)->value,
                    /*
                     * What shape the picture is, so a tile reserves its own space before the
                     * bytes arrive and the block below it does not jump. Null until the
                     * derivative has been made, which is a square tile rather than a broken one.
                     */
                    'image' => $file->imageDimensions(),
                    'uploadedAt' => $file->created_at?->toIso8601String(),
                    'uploader' => PersonSummary::fromNullable($file->uploader),
                    'canDelete' => $file->uploaded_by === $actor->id || $canModerate,
                ];
            })
            ->all());
    }

    /**
     * The projects this task appears in — each with the column it sits in, the columns it could
     * sit in instead, and whether the actor may still change what that project holds.
     *
     * `canChange` is one flag because moving and detaching are one permission: a card belongs to
     * a project, and `TaskProjectMembershipPolicy` answers both with `task.update`. Two flags
     * carrying one rule is two things to keep in step.
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
                'sections' => $placement->project->sections
                    ->map(fn (Section $section): array => ['id' => $section->id, 'name' => $section->name])
                    ->values()
                    ->all(),
                'canChange' => $placement->project->allowsChangesBy($actor, Capability::TaskUpdate),
            ])
            ->all();

        return array_values($rows);
    }

    /**
     * The projects this task could be added to: the ones the actor may change the contents
     * of, minus the ones it is already in.
     *
     * Answered from `VisibleProjectsForUser` rather than from every project in the workspace,
     * so a private project nobody gave the actor never appears in a menu — the same rule the
     * placement list follows.
     *
     * @return list<array{id: string, name: string}>
     */
    private function availableProjects(Task $task, User $actor): array
    {
        $already = $task->placements->pluck('project_id')->all();

        $rows = app(VisibleProjectsForUser::class)
            ->query($task->workspace, $actor)
            // The workspace comes with them: `allowsChangesBy()` asks it, and asking per
            // project would be a query per row in a menu.
            ->with('workspace')
            ->orderBy('name')
            ->get()
            ->reject(fn (Project $project): bool => in_array($project->id, $already, strict: true))
            ->filter(fn (Project $project): bool => $project->allowsChangesBy($actor, Capability::TaskUpdate))
            ->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name])
            ->all();

        return array_values($rows);
    }
}
