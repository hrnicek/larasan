<?php

declare(strict_types=1);

namespace App\Http\Requests\Search;

use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
{
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
            // Nullable: ConvertEmptyStringsToNull turns a cleared `?q=` into null.
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'project' => ['sometimes', 'uuid'],
            'assignee' => ['sometimes', 'integer'],
            'completed' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function term(): string
    {
        return trim((string) $this->string('q'));
    }

    /**
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

        // `filled()` would drop `completed=0`.
        if ($this->exists('completed') && $this->input('completed') !== null && $this->input('completed') !== '') {
            $filters['completed'] = $this->boolean('completed');
        }

        return $filters;
    }
}
