<?php

declare(strict_types=1);

namespace App\Domain\Project\Queries;

use App\Domain\CustomField\Data\FieldSort;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Data\ListColumns;
use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\CustomFieldType;
use App\Domain\Shared\Payloads\PersonSummary;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection as Grouped;

final class ProjectListQuery
{
    /**
     * @var Collection<string, CustomField>
     */
    private Collection $fields;

    /**
     * @param  list<string>  $tags
     * @param  array<string, string>  $fieldFilters  field id => the answer a row must carry
     * @return array{
     *     fields: list<array{id: string, name: string, type: string}>,
     *     columns: list<array{key: string, kind: string, label: string, type: string|null}>,
     *     sections: list<array{id: string|null, name: string|null, color: string|null, count: int, tasks: list<array<string, mixed>>}>,
     *     can: array{createTask: bool, updateTask: bool, deleteTask: bool},
     * }
     */
    public function __invoke(
        Project $project,
        User $actor,
        array $tags = [],
        ?FieldSort $sort = null,
        array $fieldFilters = [],
    ): array {
        $project->loadMissing('customFields');

        /** @var Collection<string, CustomField> $fields */
        $fields = $project->customFields->keyBy('id');
        $this->fields = $fields;

        $cards = $this->cards($project, $tags, $sort, $fieldFilters);

        $sections = $project->sections()->get()
            ->map(fn (Section $section): array => $this->group(
                $section->id,
                $section->name,
                $section->color?->value,
                $cards->get($section->id) ?? new Collection,
            ))
            ->all();

        $ungrouped = $cards->get('') ?? new Collection;

        if ($ungrouped->isNotEmpty()) {
            $sections[] = $this->group(null, null, null, $ungrouped);
        }

        return [
            'sections' => array_values($sections),
            'fields' => array_values($project->customFields
                ->map(fn (CustomField $field): array => [
                    'id' => $field->id,
                    'name' => $field->name,
                    'type' => $field->type->value,
                ])
                ->all()),
            'columns' => ListColumns::describe($project),
            'can' => [
                'createTask' => $actor->can('createTask', $project),
                'updateTask' => $project->allowsChangesBy($actor, Capability::TaskUpdate),
                'deleteTask' => $project->allowsChangesBy($actor, Capability::TaskDelete),
                'createSection' => $project->allowsChangesBy($actor, Capability::SectionCreate),
                'updateSection' => $project->allowsChangesBy($actor, Capability::SectionUpdate),
                'deleteSection' => $project->allowsChangesBy($actor, Capability::SectionDelete),
            ],
        ];
    }

    /**
     * @param  list<string>  $tags
     * @param  array<string, string>  $fieldFilters
     * @return Grouped<string, Collection<int, TaskProjectMembership>>
     */
    private function cards(Project $project, array $tags, ?FieldSort $sort, array $fieldFilters): Grouped
    {
        $query = TaskProjectMembership::query()
            ->visible()
            ->taggedWithAll($tags)
            ->where('project_id', $project->id)
            ->with(['task' => function (Relation $tasks): void {
                $tasks
                    ->select(['id', 'workspace_id', 'title', 'completed_at', 'due_at', 'priority', 'assignee_id'])
                    ->withCount('comments')
                    ->with([PersonSummary::eager('assignee'), 'tags:id,name,color', 'customFieldValues']);
            }])
            ->orderBy('position');

        foreach ($fieldFilters as $fieldId => $answer) {
            $field = $this->fields->get($fieldId);

            if (! $field instanceof CustomField) {
                continue;
            }

            $stored = $field->type->normalise($answer);

            $query->whereHas(
                'task.customFieldValues',
                fn (Builder $values): Builder => $values
                    ->where('custom_field_id', $field->id)
                    ->where($field->type->column(), $stored),
            );
        }

        if ($sort instanceof FieldSort) {
            $this->orderByField($query, $sort);
        }

        return $query
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
            'count' => $cards->count(),
            'tasks' => array_values($cards->map(fn (TaskProjectMembership $card): array => $this->card($card))->all()),
        ];
    }

    /**
     * @param  Builder<TaskProjectMembership>  $query
     */
    private function orderByField(Builder $query, FieldSort $sort): void
    {
        // Literal column names only: this value is interpolated into raw SQL.
        $column = match ($sort->field->type) {
            CustomFieldType::Text,
            CustomFieldType::Email,
            CustomFieldType::Phone,
            CustomFieldType::Link => 'value_text',
            CustomFieldType::Number => 'value_number',
            CustomFieldType::Date => 'value_date',
            CustomFieldType::Boolean => 'value_boolean',
            CustomFieldType::Select => 'value_option_id',
        };

        $direction = $sort->descending ? 'desc' : 'asc';

        $query
            ->leftJoin('task_custom_field_values', function (JoinClause $join) use ($sort): void {
                $join->on('task_custom_field_values.task_id', '=', 'task_project_memberships.task_id')
                    ->where('task_custom_field_values.custom_field_id', '=', $sort->field->id);
            })
            ->select('task_project_memberships.*')
            ->reorder()
            ->orderByRaw("task_custom_field_values.{$column} {$direction} nulls last")
            ->orderBy('task_project_memberships.position');
    }

    /**
     * @return array<string, string|float|bool|null>
     */
    private function answers(Task $task): array
    {
        $answers = [];

        foreach ($task->customFieldValues as $value) {
            $field = $this->fields->get($value->custom_field_id);

            if (! $field instanceof CustomField) {
                continue;
            }

            if ($value->value($field) === null) {
                continue;
            }

            // Decimal columns read back as strings, so numbers are cast before reaching the client.
            $answers[$field->id] = match ($field->type) {
                CustomFieldType::Number => (float) $value->value_number,
                CustomFieldType::Boolean => (bool) $value->value_boolean,
                CustomFieldType::Date => $value->value_date?->toDateString(),
                CustomFieldType::Text,
                CustomFieldType::Email,
                CustomFieldType::Phone,
                CustomFieldType::Link => (string) $value->value_text,
                CustomFieldType::Select => (string) $value->value_option_id,
            };
        }

        return $answers;
    }

    /**
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
            'comments' => (int) ($task->comments_count ?? 0),
            'fields' => $this->answers($task),
            'tags' => array_values($task->tags
                ->map(fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color?->value,
                ])
                ->all()),
            'assignee' => PersonSummary::fromNullable($assignee),
        ];
    }
}
