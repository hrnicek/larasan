<?php

declare(strict_types=1);

namespace App\Domain\CustomField\Actions;

use App\Domain\CustomField\Exceptions\CustomFieldException;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\CustomFieldOption;
use App\Domain\CustomField\Models\TaskCustomFieldValue;
use App\Domain\Task\Models\Task;
use App\Models\User;

/**
 * Fill a field in on a task.
 *
 * Editing a value is editing the task, so the question is `update` on the task — reach and
 * `task.update`, answered once by `TaskPolicy`, exactly as tagging asks.
 *
 * Two rules the schema cannot carry:
 *
 * - the field has to belong to the task's workspace **and** be shown on one of its projects,
 *   because a value on a field no screen renders is data with no way back out;
 * - a `select` answer has to be one of *this* field's options, or the row points at a choice
 *   from another list.
 *
 * Clearing removes the row rather than writing an empty one: "no answer" and "an answer that is
 * blank" are the same thing to a reader and two different things to a query.
 */
final readonly class SetTaskCustomFieldValue
{
    public function handle(Task $task, CustomField $field, User $actor, mixed $value): ?TaskCustomFieldValue
    {
        if ($actor->cannot('update', $task)) {
            throw CustomFieldException::cannotSetValue();
        }

        if ($field->workspace_id !== $task->workspace_id) {
            throw CustomFieldException::fieldIsFromAnotherWorkspace();
        }

        if (! $this->shownOn($task, $field)) {
            throw CustomFieldException::fieldIsNotOnThisTask();
        }

        $stored = $field->type->normalise($value);

        if ($stored === null) {
            $task->customFieldValues()->where('custom_field_id', $field->id)->delete();

            return null;
        }

        if ($field->type->isSelect() && ! $this->isOwnOption($field, (string) $stored)) {
            throw CustomFieldException::optionIsNotOnThisField();
        }

        // Found or made by hand: nothing on this model is fillable, so `firstOrNew` would be
        // mass assignment of the two ids that decide what the row means.
        $answer = $task->customFieldValues()->where('custom_field_id', $field->id)->first()
            ?? new TaskCustomFieldValue;

        // Every column emptied first: a field cannot hold two answers (the CHECK says so), and a
        // value replaced after a type migration would otherwise leave the old column filled.
        foreach ($field->type::columns() as $column) {
            $answer->setAttribute($column, null);
        }

        $answer->setAttribute($field->type->column(), $stored);
        $answer->custom_field_id = $field->id;
        $answer->task_id = $task->id;
        $answer->save();

        return $answer;
    }

    private function shownOn(Task $task, CustomField $field): bool
    {
        return $task->placements()
            ->whereHas(
                'project.customFields',
                fn ($fields) => $fields->where('custom_fields.id', $field->id),
            )
            ->exists();
    }

    private function isOwnOption(CustomField $field, string $optionId): bool
    {
        return CustomFieldOption::query()
            ->whereKey($optionId)
            ->where('custom_field_id', $field->id)
            ->exists();
    }
}
