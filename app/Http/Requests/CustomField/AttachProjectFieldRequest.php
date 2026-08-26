<?php

declare(strict_types=1);

namespace App\Http\Requests\CustomField;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Showing one of the workspace's fields on a project.
 *
 * `custom_field.manage` rather than `update` on the project: adding a column to everybody's board
 * is the same kind of decision as inventing one, which is the rule `AttachFieldToProject` states
 * for every caller.
 */
class AttachProjectFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && $this->user()?->can(Capability::CustomFieldManage->value, $project->workspace) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $project = $this->route('project');

        return [
            /*
             * Scoped to the project's workspace here as well as in the Action: a valid id from
             * another workspace is a payload nobody should be able to send, and the two answers
             * differ — this one is a validation error on the control that offered the choice,
             * the Action's is the invariant that holds for the console and the queue too.
             */
            'field' => [
                'required',
                'uuid',
                Rule::exists('custom_fields', 'id')->where(
                    'workspace_id',
                    $project instanceof Project ? $project->workspace_id : null,
                ),
            ],
        ];
    }
}
