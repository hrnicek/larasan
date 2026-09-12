<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspace;

use App\Domain\Workspace\Models\Workspace;
use App\Http\Middleware\ResolveCurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = ResolveCurrentWorkspace::from($this);

        return $workspace instanceof Workspace
            && $this->user()?->can('update', $workspace) === true;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id.in' => __('This form was opened for a different workspace. Reload the page and try again.'),
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $workspace = ResolveCurrentWorkspace::from($this);

        return [
            // The route acts on the current workspace, so this stops a form opened for one workspace
            // from writing into another that was switched to in a different tab.
            'id' => ['required', 'uuid', Rule::in([$workspace?->id])],
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('workspaces', 'slug')->ignore($workspace?->id),
            ],
            'timezone' => ['nullable', 'string', 'timezone'],
        ];
    }
}
