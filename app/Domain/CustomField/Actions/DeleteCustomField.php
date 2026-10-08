<?php

declare(strict_types=1);

namespace App\Domain\CustomField\Actions;

use App\Domain\CustomField\Exceptions\CustomFieldException;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;

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
