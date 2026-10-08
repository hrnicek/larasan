<?php

declare(strict_types=1);

namespace App\Http\Requests\Search;

use App\Domain\Shared\Enums\SearchKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchSuggestionsRequest extends FormRequest
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
            // Nullable: ConvertEmptyStringsToNull turns the palette's empty `?q=` into null.
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
