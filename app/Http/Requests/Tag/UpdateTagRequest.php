<?php

declare(strict_types=1);

namespace App\Http\Requests\Tag;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Rules\IsAccentColor;
use App\Domain\Tag\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tag = $this->route('tag');

        return $tag instanceof Tag
            && $this->user()?->can(Capability::TagManage->value, $tag->workspace) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // `sometimes`, so recolouring does not have to resend the name and renaming does not
            // have to resend the colour.
            'name' => ['sometimes', 'required', 'string', 'max:40'],
            'color' => ['sometimes', 'nullable', new IsAccentColor],
        ];
    }
}
