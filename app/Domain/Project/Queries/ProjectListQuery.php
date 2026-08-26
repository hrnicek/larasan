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
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\JoinClause;
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
final class ProjectListQuery
{
    /**
     * The project's fields, keyed by id, set once per read. A row answers the columns above it,
     * so the definitions are the project's rather than the workspace's — a value for a field
     * this project does not show is not a column anybody is looking at.
     *
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

        // Only when it holds something. An empty "no column" group on every board is noise;
        // a non-empty one is work somebody has to be able to see.
        if ($ungrouped->isNotEmpty()) {
            $sections[] = $this->group(null, null, null, $ungrouped);
        }

        return [
            'sections' => array_values($sections),
            /*
             * The project's fields, once, as the columns the rows answer. Sending the definition
             * per row would repeat it as many times as there are cards.
             */
            'fields' => array_values($project->customFields
                ->map(fn (CustomField $field): array => [
                    'id' => $field->id,
                    'name' => $field->name,
                    'type' => $field->type->value,
                ])
                ->all()),
            /*
             * The columns, in the order this project draws them, with the label each one carries
             * in the header. The header and the rows are two components and both read this — a
             * header that has drifted from the cell beneath it labels the wrong thing with
             * confidence, which is the rule `listColumns` already keeps for their widths.
             *
             * The task name is not here: it is always first, so a list that could omit it would
             * be a list with no titles in it.
             */
            'columns' => ListColumns::describe($project),
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
                /*
                 * The list's own columns are editable from the list now, not only from the
                 * settings screen, so the screen has to be told which of those it may offer.
                 * Asked the same way every other capability is (ADR-0010): the client renders
                 * the answer and never works it out.
                 */
                'createSection' => $project->allowsChangesBy($actor, Capability::SectionCreate),
                'updateSection' => $project->allowsChangesBy($actor, Capability::SectionUpdate),
                'deleteSection' => $project->allowsChangesBy($actor, Capability::SectionDelete),
            ],
        ];
    }

    /**
     * Every visible card in the project, in position order, keyed by section — one query for
     * the placements and one for their tasks, whatever the board's size.
     *
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
                    // A subquery per card's count, not a query per card. Removed comments are
                    // excluded by the model's own soft-delete scope rather than by a condition
                    // written here twice.
                    ->withCount('comments')
                    // The answers for the whole page in one read: a column of values is worth
                    // nothing if drawing it costs a query per row.
                    ->with(['assignee:id,name,email', 'tags:id,name,color', 'customFieldValues']);
            }])
            ->orderBy('position');

        foreach ($fieldFilters as $fieldId => $answer) {
            $field = $this->fields->get($fieldId);

            if (! $field instanceof CustomField) {
                // A field this project does not show filters nothing. A stale link renders the
                // list rather than an error, the way a deleted tag does (TASK-140-005).
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
            // The same collection the rows come from, so the header cannot disagree with them.
            'count' => $cards->count(),
            'tasks' => array_values($cards->map(fn (TaskProjectMembership $card): array => $this->card($card))->all()),
        ];
    }

    /**
     * Order by a field's answer, in the database.
     *
     * A left join rather than a subquery per row, and **nulls last** in both directions: a row
     * nobody has answered is not the smallest value, it is an absence, and burying it at the top
     * of an ascending list is how a column of blanks becomes the first thing anybody sees.
     *
     * The column is the type's, which is the whole reason the values are stored in typed columns
     * — a number sorts numerically and a date chronologically without a cast per row.
     *
     * @param  Builder<TaskProjectMembership>  $query
     */
    private function orderByField(Builder $query, FieldSort $sort): void
    {
        /*
         * Written out rather than interpolated from a method call: this string reaches the
         * database as SQL, and the only safe kind of that is one the code states literally.
         */
        $column = match ($sort->field->type) {
            // The four text-shaped types share the column, and therefore the ordering: an
            // address, a number to call and a link all sort as text.
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
            // Then by position, so two rows with the same answer keep the order somebody put
            // them in rather than swapping between requests.
            ->orderBy('task_project_memberships.position');
    }

    /**
     * This row's answers, keyed by field id.
     *
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

            /*
             * Each branch reads its own column. A decimal reads back as a string, and a number
             * sent as `"12.500000"` sorts like text on the client — which is the sort of thing
             * that only shows up in somebody's ordering.
             */
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
            'comments' => (int) ($task->comments_count ?? 0),
            /*
             * Keyed by field, because a row answers the columns above it — a list would have to
             * be read positionally, and a project whose fields changed between two requests
             * would then shift every row's values sideways.
             */
            'fields' => $this->answers($task),
            'tags' => array_values($task->tags
                ->map(fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color?->value,
                ])
                ->all()),
            'assignee' => $assignee === null ? null : [
                'id' => $assignee->id,
                'name' => $assignee->name,
                'email' => $assignee->email,
                'avatar' => null,
            ],
        ];
    }
}
