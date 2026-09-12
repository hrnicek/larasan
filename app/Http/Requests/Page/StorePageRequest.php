<?php

declare(strict_types=1);

namespace App\Http\Requests\Page;

use App\Domain\Project\Models\Project;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->project();

        return $project instanceof Project
            && $this->user()?->can('createPage', $project) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],

            // Scoped to the project, since `exists` alone would accept a page from another project.
            'parent' => [
                'nullable', 'uuid',
                Rule::exists('pages', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('project_id', $this->project()?->id)
                        ->whereNull('deleted_at'),
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['parent.exists' => __('That page is not in this project.')];
    }

    private function project(): ?Project
    {
        $project = $this->route('project');

        return $project instanceof Project ? $project : null;
    }
}
