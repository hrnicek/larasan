<?php

declare(strict_types=1);

namespace App\Http\Requests\Search;

use App\Domain\Shared\Enums\SearchKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchSuggestionsRequest extends FormRequest
{
    /**
     * Anybody signed in may type into the palette; what they *find* is the query's answer, not
     * this one — the same division `SearchRequest` makes (TASK-160-002).
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
            // Bounded because it reaches a search engine: a term nobody could have typed is a
            // term nobody meant.
            'q' => ['sometimes', 'string', 'max:200'],
            'kind' => ['sometimes', 'nullable', Rule::enum(SearchKind::class)],
        ];
    }

    public function term(): string
    {
        return trim((string) $this->string('q'));
    }

    public function kind(): ?SearchKind
    {
        return $this->enum('kind', SearchKind::class);
    }
}
