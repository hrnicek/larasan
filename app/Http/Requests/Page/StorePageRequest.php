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
            // Optional: a page is created and then named, so demanding a title first would
            // make the empty page a form.
            'title' => ['nullable', 'string', 'max:255'],

            /*
             * The page this one is written inside. Scoped to the project in the URL rather
             * than validated as a bare uuid: an id from another project is the shape a
             * cross-tenant write takes, and `exists` alone would accept it.
             */
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
