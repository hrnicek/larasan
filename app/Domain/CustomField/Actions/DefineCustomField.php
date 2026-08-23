<?php

declare(strict_types=1);

namespace App\Domain\CustomField\Actions;

use App\Domain\CustomField\Exceptions\CustomFieldException;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\CustomFieldOption;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\CustomFieldType;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Decide that a workspace records something new about its work.
 *
 * `custom_field.manage` rather than `task.update`: a field is a column on everybody's screens,
 * and ADR-0010 gives that to owners and admins. Filling one in is a different question, asked by
 * `SetTaskCustomFieldValue`.
 *
 * A `select` field is created with its choices in one transaction, because a choice field with
 * no choices is a control nobody can use and a state nothing else in this phase expects.
 */
final readonly class DefineCustomField
{
    /**
     * @param  list<string>  $options  labels, in the order they should be offered
     */
    public function handle(
        Workspace $workspace,
        User $actor,
        string $name,
        CustomFieldType $type,
        array $options = [],
    ): CustomField {
        if (! $workspace->membershipFor($actor)?->allows(Capability::CustomFieldManage)) {
            throw CustomFieldException::cannotManageFields();
        }

        $name = trim($name);

        if ($name === '') {
            throw CustomFieldException::nameIsEmpty();
        }

        $labels = array_values(array_filter(array_map(trim(...), $options), fn (string $label): bool => $label !== ''));

        if ($type->isSelect() && $labels === []) {
            throw CustomFieldException::selectNeedsOptions();
        }

        $field = new CustomField(['name' => $name]);
        $field->workspace_id = $workspace->id;
        $field->type = $type;

        try {
            /*
             * Its own transaction, so a duplicate name is a rolled-back savepoint rather than a
             * poisoned connection — the same reason `CreateTag` has one. The unique index is
             * what answers, because two people can define "Estimate" in the same second.
             */
            DB::transaction(function () use ($field, $labels): void {
                $field->save();

                foreach ($labels as $position => $label) {
                    $option = new CustomFieldOption(['label' => $label, 'position' => $position + 1]);
                    $option->custom_field_id = $field->id;
                    $option->save();
                }
            });
        } catch (QueryException $exception) {
            throw CustomFieldException::nameIsTaken();
        }

        return $field;
    }
}
