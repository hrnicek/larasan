<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectIcon;
use App\Domain\Shared\Rules\IsAccentColor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
