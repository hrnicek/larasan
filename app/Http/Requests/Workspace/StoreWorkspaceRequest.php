<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkspaceRequest extends FormRequest
{
    /**
     * Creating a workspace is not scoped to a workspace, so there is no model for a
     * policy to judge and no capability that could grant or withhold it: any
     * authenticated user may create one. The `auth` middleware is the check, and stating
     * that here is the point — an empty `authorize()` reads like an oversight.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('workspaces', 'slug')],
            'timezone' => ['nullable', 'string', 'timezone'],
        ];
    }
}
