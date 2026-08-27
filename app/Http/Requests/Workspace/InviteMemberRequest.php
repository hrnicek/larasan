<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspace;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Middleware\ResolveCurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = ResolveCurrentWorkspace::from($this);

        return $workspace instanceof Workspace
            && $this->user()?->can(Capability::WorkspaceMembersManage->value, $workspace) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            /*
             * No `exists` rule: an invitation is addressed to an email, and whether an
             * account answers to it is the Action's question rather than the form's
             * (TASK-270-001). Bounded because the column is a `varchar(255)`.
             */
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::enum(WorkspaceRole::class), Rule::notIn([WorkspaceRole::Owner->value])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.not_in' => __('Ownership is transferred, not assigned.'),
        ];
    }
}
