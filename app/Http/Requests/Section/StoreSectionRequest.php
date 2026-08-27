<?php

declare(strict_types=1);

namespace App\Http\Requests\Section;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Rules\IsAccentColor;
use Illuminate\Foundation\Http\FormRequest;

class StoreSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && $this->user()?->can('createSection', $project) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // The name is user content and nothing reads it back (ADR-0004), so it is
            // bounded and otherwise unconstrained — "Done" and "done ✅" are both columns.
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', new IsAccentColor],
        ];
    }
}
