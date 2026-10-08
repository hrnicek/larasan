<?php

declare(strict_types=1);

namespace App\Domain\CustomField\Actions;

use App\Domain\CustomField\Exceptions\CustomFieldException;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\ProjectCustomField;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;

final readonly class AttachFieldToProject
{
    public function handle(Project $project, CustomField $field, User $actor): ProjectCustomField
    {
        if (! $project->workspace->membershipFor($actor)?->allows(Capability::CustomFieldManage)) {
            throw CustomFieldException::cannotManageFields();
        }

        // The pivot cannot enforce that both sides belong to the same workspace. See ADR-0005.
        if ($field->workspace_id !== $project->workspace_id) {
            throw CustomFieldException::fieldIsFromAnotherWorkspace();
        }

        $existing = ProjectCustomField::query()
            ->where('project_id', $project->id)
            ->where('custom_field_id', $field->id)
            ->first();

        if ($existing instanceof ProjectCustomField) {
            return $existing;
        }

        $attached = new ProjectCustomField([
            'position' => (int) ProjectCustomField::query()->where('project_id', $project->id)->max('position') + 1,
        ]);

        $attached->project_id = $project->id;
        $attached->custom_field_id = $field->id;
        $attached->save();

        return $attached;
    }
}
