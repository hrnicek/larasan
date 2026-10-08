<?php

declare(strict_types=1);

namespace App\Http\Requests\Section;

use App\Domain\Section\Models\Section;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $section = $this->section();

        return $section instanceof Section
            && $this->user()?->can('update', $section) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $section = $this->section();

        return [
            'after' => [
                'nullable', 'uuid',
                Rule::exists('sections', 'id')->where(
                    fn (Builder $query): Builder => $query->where('project_id', $section?->project_id),
                ),
                Rule::notIn([$section?->id]),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'after.exists' => __('That section is not in this project.'),
            'after.not_in' => __('A section cannot be placed after itself.'),
        ];
    }

    private function section(): ?Section
    {
        $section = $this->route('section');

        return $section instanceof Section ? $section : null;
    }
}
