<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectIcon;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Rules\IsAccentColor;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->project();

        return $project instanceof Project
            && $this->user()?->can('update', $project) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $project = $this->project();

        return [
            /*
             * The same guard `UpdateWorkspaceRequest` carries, one level down: settings
             * opened for one project and submitted after switching to another would
             * otherwise write the first project's values into the second, with every
             * check upstream passing because the actor manages both.
             */
            'id' => ['required', 'uuid', Rule::in([$project?->id])],
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('projects', 'slug')
                    ->where(fn (Builder $query): Builder => $query->where('workspace_id', $project?->workspace_id))
                    ->ignore($project?->id),
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
            'id.in' => __('This form was opened for a different project. Reload the page and try again.'),
            'due_date.after_or_equal' => __('A project cannot be due before it starts.'),
        ];
    }

    private function project(): ?Project
    {
        $project = $this->route('project');

        return $project instanceof Project ? $project : null;
    }
}
