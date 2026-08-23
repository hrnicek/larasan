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
 * Show one of the workspace's fields on a project.
 *
 * `custom_field.manage`, like defining: adding a column to everybody's board is the same kind of
 * decision as inventing one, and neither is what `task.update` is for.
 */
final readonly class AttachFieldToProject
{
    public function handle(Project $project, CustomField $field, User $actor): ProjectCustomField
    {
        if (! $project->workspace->membershipFor($actor)?->allows(Capability::CustomFieldManage)) {
            throw CustomFieldException::cannotManageFields();
        }

        // Two valid ids that must not be combined; the pivot cannot say it (ADR-0005).
        if ($field->workspace_id !== $project->workspace_id) {
            throw CustomFieldException::fieldIsFromAnotherWorkspace();
        }

        $existing = ProjectCustomField::query()
            ->where('project_id', $project->id)
            ->where('custom_field_id', $field->id)
            ->first();

        // Attaching twice is the same column, not two of them.
        if ($existing instanceof ProjectCustomField) {
            return $existing;
        }

        $attached = new ProjectCustomField([
            // At the end, because a field added today is not more important than the ones
            // already there — reordering is its own operation and nobody has asked for it yet.
            'position' => (int) ProjectCustomField::query()->where('project_id', $project->id)->max('position') + 1,
        ]);

        $attached->project_id = $project->id;
        $attached->custom_field_id = $field->id;
        $attached->save();

        return $attached;
    }
}
