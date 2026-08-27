<?php

declare(strict_types=1);

namespace App\Http\Requests\Tag;

use App\Domain\Shared\Rules\IsAccentColor;
use App\Domain\Task\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Put a tag on a task, naming either one that exists or one that does not yet.
 *
 * The authorization here is `update` on the task, because that is what labelling a task is.
 * Inventing a word for the workspace is a second, stricter question — `tag.manage` — and it is
 * asked by `CreateTag`, only on the branch that reaches it.
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
            // One or the other: an id names a tag that exists, a name is offered when nothing in
            // the vocabulary matched what somebody typed.
            'tag' => ['required_without:name', 'uuid'],
            'name' => ['required_without:tag', 'string', 'max:40'],
            'color' => ['nullable', new IsAccentColor],
        ];
    }
}
