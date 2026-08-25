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
            /*
             * The shape only. What the document may contain is `PageDocument`'s answer, not
             * a validation rule — the rules would be a second copy of the allowlist, and the
             * copy that goes out of date.
             */
            'content' => ['required', 'array'],
            'content.type' => ['required', 'string', 'in:doc'],

            // What the editor last read. The Action refuses a save that carries an older
            // number rather than overwriting whatever arrived in the meantime.
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
