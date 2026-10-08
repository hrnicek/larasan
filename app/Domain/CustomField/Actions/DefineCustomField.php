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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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
            // A savepoint, so a duplicate-name violation does not abort an enclosing Postgres transaction.
            DB::transaction(function () use ($field, $labels): void {
                $field->save();

                foreach ($labels as $position => $label) {
                    $option = new CustomFieldOption(['label' => $label, 'position' => $position + 1]);
                    $option->custom_field_id = $field->id;
                    $option->save();
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw CustomFieldException::nameIsTaken();
        }

        return $field;
    }
}
