<?php

declare(strict_types=1);

namespace App\Http\Requests\Page;

use App\Domain\Page\Models\Page;
use Illuminate\Foundation\Http\FormRequest;

class SavePageContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $page = $this->route('page');

        return $page instanceof Page && $this->user()?->can('update', $page) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Shape only; the allowed content is enforced by `PageDocument`.
            'content' => ['required', 'array'],
            'content.type' => ['required', 'string', 'in:doc'],

            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
