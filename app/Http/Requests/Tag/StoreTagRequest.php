<?php

declare(strict_types=1);

namespace App\Http\Requests\Tag;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Rules\IsAccentColor;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Middleware\ResolveCurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;

class StoreTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = ResolveCurrentWorkspace::from($this);

        return $workspace instanceof Workspace
            && $this->user()?->can(Capability::TagManage->value, $workspace) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Short, because a tag is a word rather than a sentence: anything longer stops
            // fitting on the card it exists to label.
            'name' => ['required', 'string', 'max:40'],
            'color' => ['nullable', new IsAccentColor],
        ];
    }
}
