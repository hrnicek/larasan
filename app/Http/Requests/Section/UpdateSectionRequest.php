<?php

declare(strict_types=1);

namespace App\Http\Requests\Section;

use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectColor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $section = $this->route('section');

        return $section instanceof Section
            && $this->user()?->can('update', $section) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', Rule::enum(ProjectColor::class)],
        ];
    }
}
