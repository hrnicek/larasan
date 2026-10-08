<?php

declare(strict_types=1);

namespace App\Domain\CustomField\Actions;

use App\Domain\CustomField\Exceptions\CustomFieldException;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\CustomFieldOption;
use App\Domain\CustomField\Models\TaskCustomFieldValue;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Task\Models\Task;
use App\Models\User;

final readonly class SetTaskCustomFieldValue
{
    public function __construct(private VisibleProjectsForUser $visibleProjects) {}

    public function handle(Task $task, CustomField $field, User $actor, mixed $value): ?TaskCustomFieldValue
    {
        if ($actor->cannot('update', $task)) {
            throw CustomFieldException::cannotSetValue();
        }

        if ($field->workspace_id !== $task->workspace_id) {
            throw CustomFieldException::fieldIsFromAnotherWorkspace();
        }

        if (! $this->shownOn($task, $field, $actor)) {
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

        // Not firstOrNew: the model is fully guarded, so the ids are assigned explicitly.
        $answer = $task->customFieldValues()->where('custom_field_id', $field->id)->first()
            ?? new TaskCustomFieldValue;

        // A CHECK constraint allows only one value column to be filled.
        foreach ($field->type::columns() as $column) {
            $answer->setAttribute($column, null);
        }

        $answer->setAttribute($field->type->column(), $stored);
        $answer->custom_field_id = $field->id;
        $answer->task_id = $task->id;
        $answer->save();

        return $answer;
    }

    private function shownOn(Task $task, CustomField $field, User $actor): bool
    {
        return $task->placements()
            ->whereIn(
                'project_id',
                $this->visibleProjects->query($task->workspace, $actor, includeArchived: true)->select('projects.id'),
            )
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
