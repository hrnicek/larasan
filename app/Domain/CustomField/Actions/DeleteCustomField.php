<?php

declare(strict_types=1);

namespace App\Domain\CustomField\Actions;

use App\Domain\CustomField\Exceptions\CustomFieldException;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;

/**
 * Stop recording something.
 *
 * Hard, and the answers go with it: a field's values mean nothing without the field, and the
 * cascade says so. That is why detaching a field from a project deliberately does *not* delete
 * them — the two operations differ in exactly this.
 */
final readonly class DeleteCustomField
{
    public function handle(CustomField $field, User $actor): void
    {
        if (! $field->workspace->membershipFor($actor)?->allows(Capability::CustomFieldManage)) {
            throw CustomFieldException::cannotManageFields();
        }

        $field->delete();
    }
}
