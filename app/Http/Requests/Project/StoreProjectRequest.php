<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectIcon;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Rules\IsAccentColor;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Middleware\ResolveCurrentWorkspace;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = ResolveCurrentWorkspace::from($this);

        return $workspace instanceof Workspace
            && $this->user()?->can(Capability::ProjectCreate->value, $workspace) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $workspace = ResolveCurrentWorkspace::from($this);

        return [
            'name' => ['required', 'string', 'max:255'],
            // Checks the table, not the model, so soft-deleted projects still hold their slugs.
            'slug' => [
                'nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('projects', 'slug')->where(
                    fn (Builder $query): Builder => $query->where('workspace_id', $workspace?->id),
                ),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['nullable', new IsAccentColor],
            'icon' => ['nullable', Rule::enum(ProjectIcon::class)],
            'default_view' => ['nullable', Rule::enum(ProjectDefaultView::class)],
            'visibility' => ['nullable', Rule::enum(ProjectVisibility::class)],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'due_date.after_or_equal' => __('A project cannot be due before it starts.'),
        ];
    }
}
