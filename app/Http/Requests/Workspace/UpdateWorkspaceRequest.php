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
            /*
             * The route names no workspace — it acts on the one the request is in — so
             * the form carries the id it was rendered for. Without this, opening
             * settings for A, switching to B in another tab and submitting writes A's
             * values into B, and every check upstream passes because the actor is a
             * legitimate admin of both.
             */
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
