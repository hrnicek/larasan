<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use Illuminate\Foundation\Http\FormRequest;

class ReorderProjectColumnsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && $this->user()?->can(Capability::CustomFieldManage->value, $project->workspace) === true;
    }

    /**
     * The order whole, in the order to draw it.
     *
     * Each entry is a field id or one of the built-in keys, and neither is checked here: a key the
     * project cannot draw is not a lie about the world, it is a key that means nothing, and the
     * Action drops it. What validation is for is the shape — a payload that is not a list of
     * strings would be stored and then drawn.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'columns' => ['present', 'array', 'max:60'],
            'columns.*' => ['required', 'string', 'max:64'],
        ];
    }
}
