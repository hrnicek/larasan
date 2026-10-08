<?php

declare(strict_types=1);

namespace App\Domain\CustomField\Actions;

use App\Domain\CustomField\Exceptions\CustomFieldException;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\ProjectCustomField;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;

final readonly class DetachFieldFromProject
{
    public function handle(Project $project, CustomField $field, User $actor): void
    {
        if (! $project->workspace->membershipFor($actor)?->allows(Capability::CustomFieldManage)) {
            throw CustomFieldException::cannotManageFields();
        }

        // Task values are kept, so attaching the field again restores them.
        ProjectCustomField::query()
            ->where('project_id', $project->id)
            ->where('custom_field_id', $field->id)
            ->delete();
    }
}
