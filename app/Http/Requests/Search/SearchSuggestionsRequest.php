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
     * A form that sends everything it has sends the empty ones too. An absent kind and an empty
     * kind mean the same thing here — all four — and answering the second with a validation
     * error would make the palette's first request fail on the empty field it opens with.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('kind') === '') {
            $this->merge(['kind' => null]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            /*
             * Nullable as well as bounded: `ConvertEmptyStringsToNull` turns the `?q=` the
             * palette opens with into null, and a rule that only allows a string would answer
             * the empty field with a validation error.
             */
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
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
