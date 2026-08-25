<?php

declare(strict_types=1);

namespace App\Http\Requests\Page;

use App\Domain\Page\Models\Page;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MovePageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $page = $this->page();

        return $page instanceof Page && $this->user()?->can('update', $page) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $page = $this->page();

        /*
         * Both ids are scoped to the page's own project and neither may be the page itself.
         * The Action refuses all of this again — it also refuses a parent from inside the
         * page's own subtree, which no `exists` rule can express — but a stale tree deserves
         * a validation error rather than a domain exception.
         */
        $inTheSameProject = [
            'nullable', 'uuid',
            Rule::exists('pages', 'id')->where(
                fn (Builder $query): Builder => $query
                    ->where('project_id', $page?->project_id)
                    ->whereNull('deleted_at'),
            ),
            Rule::notIn([$page?->id]),
        ];

        return [
            'parent' => $inTheSameProject,
            'after' => $inTheSameProject,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent.exists' => __('That page is not in this project.'),
            'after.exists' => __('That page is not in this project.'),
            'parent.not_in' => __('A page cannot be moved inside itself.'),
            'after.not_in' => __('That page is not where this one would be placed.'),
        ];
    }

    private function page(): ?Page
    {
        $page = $this->route('page');

        return $page instanceof Page ? $page : null;
    }
}
