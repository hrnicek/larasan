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
             * The address must belong to an account: only an existing user can be
             * invited (TASK-030-015). `exists` says so in the language the form speaks,
             * and the Action still refuses an id it cannot use.
             */
            'email' => ['required', 'email', Rule::exists('users', 'email')],
            'role' => ['required', Rule::enum(WorkspaceRole::class), Rule::notIn([WorkspaceRole::Owner->value])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.exists' => __('Nobody with that address has an account yet.'),
            'role.not_in' => __('Ownership is transferred, not assigned.'),
        ];
    }
}
