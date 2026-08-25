<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Domain\CustomField\Data\FieldSort;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectDefaultView;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
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

            /*
             * Ordering and narrowing by a field's answer, both in the URL for the reason the tag
             * filter is: this is a view somebody sends, not a preference they describe.
             */
            'sort' => ['sometimes', 'uuid'],
            'direction' => ['sometimes', 'in:asc,desc'],
            'field' => ['sometimes', 'array', 'max:10'],

            /*
             * Which month the calendar is showing. A month rather than a date: the view draws
             * whole weeks around one month, so a day would be a more precise way of saying the
             * same thing and a worse thing to read in a link somebody sent.
             *
             * A value that is not a month is a validation error rather than a quiet fallback to
             * this one, for the reason `view` is: a URL that silently renders something else
             * looks like the control is broken.
             */
            'month' => ['sometimes', 'date_format:Y-m'],
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

    /**
     * The month the calendar is showing: the parameter when it is there, this month when it is
     * not. Always the first of it, so a caller cannot accidentally carry a day into a value
     * that means a month.
     */
    public function month(): CarbonImmutable
    {
        $month = $this->string('month')->value();

        return $month === ''
            ? CarbonImmutable::now()->startOfMonth()
            : CarbonImmutable::parse($month.'-01')->startOfMonth();
    }

    public function openTask(): ?string
    {
        return $this->string('task')->value() ?: null;
    }

    /**
     * The field this view is ordered by, when the project still shows it. A stale id orders by
     * nothing rather than 404ing, the way a stale tag filters nothing.
     *
     * @param  Collection<int, CustomField>  $available
     */
    public function sort(Collection $available): ?FieldSort
    {
        $id = $this->string('sort')->value();

        if ($id === '') {
            return null;
        }

        $field = $available->firstWhere('id', $id);

        return $field instanceof CustomField
            ? new FieldSort($field, descending: $this->string('direction')->value() === 'desc')
            : null;
    }

    /**
     * The answers a row must carry, keyed by field id.
     *
     * @return array<string, string>
     */
    public function fieldFilters(): array
    {
        /** @var array<array-key, mixed> $filters */
        $filters = $this->input('field', []);

        $answers = [];

        foreach ($filters as $fieldId => $answer) {
            // Both halves have to be readable: a key that is not an id and a value that is not
            // a scalar are a URL somebody built by hand, and neither filters anything.
            if (is_string($fieldId) && (is_string($answer) || is_numeric($answer)) && (string) $answer !== '') {
                $answers[$fieldId] = (string) $answer;
            }
        }

        return $answers;
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
     * The days the reader has asked to see in full — the calendar's half of `expand`, which the
     * board spends on columns. One parameter rather than two: a URL names one view, so the two
     * meanings can never be in the same address.
     *
     * @return list<string>
     */
    public function expandedDays(): array
    {
        return array_values(array_filter(
            $this->expandedColumns(),
            fn (string $day): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1,
        ));
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
