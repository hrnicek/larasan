<?php

declare(strict_types=1);

namespace App\Domain\CustomField\Actions;

use App\Domain\CustomField\Exceptions\CustomFieldException;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Change what a field is called.
 *
 * Only the name: the type is not editable here for the reason it is not fillable on the model —
 * it decides which column every existing answer lives in.
 */
final readonly class RenameCustomField
{
    public function handle(CustomField $field, User $actor, string $name): CustomField
    {
        if (! $field->workspace->membershipFor($actor)?->allows(Capability::CustomFieldManage)) {
            throw CustomFieldException::cannotManageFields();
        }

        $name = trim($name);

        if ($name === '') {
            throw CustomFieldException::nameIsEmpty();
        }

        $field->name = $name;

        try {
            DB::transaction(fn () => $field->save());
        } catch (QueryException $exception) {
            throw CustomFieldException::nameIsTaken();
        }

        return $field;
    }
}
