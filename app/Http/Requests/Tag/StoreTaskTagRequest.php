<?php

declare(strict_types=1);

namespace App\Http\Requests\Tag;

use App\Domain\Shared\Rules\IsAccentColor;
use App\Domain\Task\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating a tag by name additionally requires `tag.manage`, which `CreateTag` checks.
 */
class StoreTaskTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task instanceof Task && $this->user()?->can('update', $task) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'tag' => ['required_without:name', 'uuid'],
            'name' => ['required_without:tag', 'string', 'max:40'],
            'color' => ['nullable', new IsAccentColor],
        ];
    }
}
