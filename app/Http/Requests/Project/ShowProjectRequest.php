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

            /*
             * Which columns the reader has asked to see in full. A list of ids rather than a
             * page number: a board is not a sequence of pages, and "this column, all of it"
             * is the only thing a client can honestly ask for while cards are moving.
             */
            'expand' => ['sometimes', 'array', 'max:20'],
            'expand.*' => ['string', 'max:64'],

            /*
             * The task whose panel is open over this screen. It lives in the URL because a
             * panel is a context plus a task: a link that carried only the task could not say
             * which board it was opened from, and reopening it would lose the context.
             */
            'task' => ['sometimes', 'uuid'],

            /*
             * The tags a card must carry, all of them. In the URL because a filtered view is a
             * link somebody sends — "the bugs in this project" is a thing people share, not a
             * setting they describe over chat.
             */
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['uuid'],
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

    public function openTask(): ?string
    {
        return $this->string('task')->value() ?: null;
    }

    /**
     * The tags this view is filtered by. Ids the workspace does not have simply match nothing,
     * which is the honest answer to a stale link — a 404 for a deleted tag would throw away a
     * board somebody can still read.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        /** @var array<int, mixed> $tags */
        $tags = $this->input('tags', []);

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $id): string => is_string($id) ? $id : '', $tags),
            fn (string $id): bool => $id !== '',
        )));
    }

    /**
     * @return list<string>
     */
    public function expandedColumns(): array
    {
        /** @var array<int, mixed> $expanded */
        $expanded = $this->input('expand', []);

        return array_values(array_filter(
            array_map(fn (mixed $id): string => is_string($id) ? $id : '', $expanded),
            fn (string $id): bool => $id !== '',
        ));
    }
}
