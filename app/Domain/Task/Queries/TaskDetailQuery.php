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
     *     collaborators: list<array<string, mixed>>,
     *     collaborating: bool,
     *     followers: list<array<string, mixed>>,
     *     following: bool,
     *     starred: bool,
     *     can: array{update: bool, assign: bool, delete: bool, comment: bool, attach: bool, manageTags: bool},
     * }
     */
    public function __invoke(Task $task, User $actor): array
    {
        $task->loadMissing([
            PersonSummary::eager('assignee'),
            PersonSummary::eager('creator'),
            // Constrained in the eager load so a parent in an unreachable project is never exposed.
            'parent' => fn (Relation $parent) => $parent
                ->whereIn('tasks.id', $this->reachable->idsFor($task->workspace, $actor))
                ->select(['tasks.id', 'tasks.title']),
            // Constrained in the eager load so a subtask in an unreachable project is never exposed.
            'children' => fn (Relation $subtasks) => $subtasks
                ->whereIn('tasks.id', $this->reachable->idsFor($task->workspace, $actor))
                ->select(['tasks.id', 'tasks.parent_id', 'tasks.title', 'tasks.completed_at']),
            PersonSummary::eager('collaborators'),
            PersonSummary::eager('followers'),
            PersonSummary::eager('attachments.file.uploader'),
            'tags:id,name,color',
            'customFieldValues',
            'placements.project.customFields.options',
            'placements' => fn (Relation $placements) => $placements->with([
                // visibility, default_access_level and archived_at feed allowsChangesBy(); strict Eloquent throws on unselected attributes.
                'project:id,workspace_id,name,color,visibility,default_access_level,archived_at',
                'project.workspace',
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
            'collaborators' => array_values($task->collaborators
                ->map(PersonSummary::from(...))
                ->all()),
            'collaborating' => $task->collaborators->contains('id', $actor->id),
            'followers' => array_values($task->followers
                ->map(PersonSummary::from(...))
                ->all()),
            'following' => $task->followers->contains('id', $actor->id),
            'starred' => $task->stars()->where('user_id', $actor->id)->exists(),
            'can' => [
                'update' => $actor->can('update', $task),
                'assign' => $actor->can('assign', $task),
                'delete' => $actor->can('delete', $task),
                'comment' => $actor->can('comment', $task),
                'attach' => $actor->can('attach', $task),
                'manageTags' => $actor->can(Capability::TagManage->value, $task->workspace),
            ],
        ];
    }

    /**
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

    private function answer(CustomField $field, ?TaskCustomFieldValue $answer): string|float|bool|null
    {
        if ($answer === null || $answer->value($field) === null) {
            return null;
        }

        // Cast by field type: decimal columns are read back as strings.
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
     * @return list<array<string, mixed>>
     */
    private function attachments(Task $task, User $actor): array
    {
        $canModerate = $task->workspace->membershipFor($actor)?->allows(Capability::FileDelete) === true;

        // The stored path is never sent; downloads go through the authorized endpoint. See ADR-0007.
        return array_values($task->attachments
            ->map(function (Attachment $attachment) use ($actor, $canModerate): array {
                $file = $attachment->file;

                return [
                    'id' => $attachment->id,
                    'name' => $file->original_name,
                    'size' => $file->size,
                    'mimeType' => $file->mime_type,
                    'kind' => FileKind::fromMime($file->mime_type, $file->extension)->value,
                    'image' => $file->imageDimensions(),
                    'uploadedAt' => $file->created_at?->toIso8601String(),
                    'uploader' => PersonSummary::fromNullable($file->uploader),
                    'canDelete' => $file->uploaded_by === $actor->id || $canModerate,
                ];
            })
            ->all());
    }

    /**
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
     * @return list<array{id: string, name: string}>
     */
    private function availableProjects(Task $task, User $actor): array
    {
        $already = $task->placements->pluck('project_id')->all();

        $rows = app(VisibleProjectsForUser::class)
            ->query($task->workspace, $actor)
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
