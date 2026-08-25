<?php

declare(strict_types=1);

namespace App\Http\Requests\Search;

use App\Domain\Shared\Enums\SearchKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSavedSearchRequest extends FormRequest
{
    /**
     * Anybody signed in may keep their own search; whose workspace it lands in is the
     * controller's answer, not this one.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'term' => ['required', 'string', 'max:200'],
            'kind' => ['sometimes', 'nullable', Rule::enum(SearchKind::class)],
            'project' => ['sometimes', 'nullable', 'uuid'],
            'assignee' => ['sometimes', 'nullable', 'integer'],
            'completed' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /**
     * The filters as the search screen carries them, so a saved search replays into the same
     * URL it was kept from.
     *
     * @return array{project?: string, assignee?: int, completed?: bool}
     */
    public function filters(): array
    {
        $filters = [];

        if ($this->filled('project')) {
            $filters['project'] = (string) $this->string('project');
        }

        if ($this->filled('assignee')) {
            $filters['assignee'] = (int) $this->integer('assignee');
        }

        // `filled()` would drop `completed=0`, which is the half of this filter people use most.
        if ($this->exists('completed') && $this->input('completed') !== null && $this->input('completed') !== '') {
            $filters['completed'] = $this->boolean('completed');
        }

        return $filters;
    }
}
