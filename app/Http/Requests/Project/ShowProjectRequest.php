<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectDefaultView;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShowProjectRequest extends FormRequest
{
    /**
     * Reading is the policy's answer, asked in the controller where the project is bound.
     * A request that authorized here would have to resolve the project a second time.
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
            // Not `nullable`: an absent parameter means "the project's own view", and an
            // empty one is a client that built a URL wrong.
            'view' => ['sometimes', Rule::enum(ProjectDefaultView::class)],
        ];
    }

    /**
     * What this request asked to see: the parameter when it is there, the project's default
     * when it is not.
     */
    public function view(Project $project): ProjectDefaultView
    {
        return $this->enum('view', ProjectDefaultView::class) ?? $project->default_view;
    }
}
