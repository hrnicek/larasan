<?php

declare(strict_types=1);

namespace App\Domain\CustomField\Actions;

use App\Domain\CustomField\Exceptions\CustomFieldException;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\ProjectCustomField;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;

/**
 * Stop showing a field on a project.
 *
 * The **values stay**. Taking a column off a board is a decision about the board, and deleting
 * what people answered because of it would make that decision unrecoverable — putting the field
 * back brings the answers with it. Deleting the field itself is what removes them, and that is
 * a different operation with a different name.
 */
final readonly class DetachFieldFromProject
{
    public function handle(Project $project, CustomField $field, User $actor): void
    {
        if (! $project->workspace->membershipFor($actor)?->allows(Capability::CustomFieldManage)) {
            throw CustomFieldException::cannotManageFields();
        }

        ProjectCustomField::query()
            ->where('project_id', $project->id)
            ->where('custom_field_id', $field->id)
            ->delete();
    }
}
