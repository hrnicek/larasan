<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectIcon;
use App\Domain\Shared\Rules\IsAccentColor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The picker sends both fields every time, so an absent one is "no colour" or "no icon"
 * rather than "leave it alone" — the control shows both and can clear either.
 *
 * `UpdateProjectRequest` carries an `id` guard because the settings form can be opened
 * for one project and submitted after switching to another. This control cannot drift
 * that way: it is drawn from the same props that name the project in its own URL.
 */
class UpdateProjectAppearanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && $this->user()?->can('update', $project) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'color' => ['nullable', new IsAccentColor],
            'icon' => ['nullable', Rule::enum(ProjectIcon::class)],
        ];
    }
}
