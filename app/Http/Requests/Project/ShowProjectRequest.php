<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Domain\CustomField\Data\FieldSort;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectFileSort;
use App\Domain\Shared\Enums\ProjectView;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class ShowProjectRequest extends FormRequest
{
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
            'view' => ['sometimes', Rule::enum(ProjectView::class)],

            'page' => ['sometimes', 'integer', 'min:1'],

            'expand' => ['sometimes', 'array', 'max:20'],
            'expand.*' => ['string', 'max:64'],

            'task' => ['sometimes', 'uuid'],

            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['uuid'],

            'sort' => $this->showsFiles()
                ? ['sometimes', Rule::enum(ProjectFileSort::class)]
                : ['sometimes', 'uuid'],
            'direction' => ['sometimes', 'in:asc,desc'],
            'field' => ['sometimes', 'array', 'max:10'],

            'month' => ['sometimes', 'date_format:Y-m'],
        ];
    }

    public function view(Project $project): ProjectView
    {
        return $this->enum('view', ProjectView::class) ?? ProjectView::fromDefault($project->default_view);
    }

    public function page(): int
    {
        return max(1, $this->integer('page', 1));
    }

    /**
     * @return array{ProjectFileSort, bool|null}
     */
    public function fileSort(): array
    {
        $direction = $this->string('direction')->value();

        return [
            $this->enum('sort', ProjectFileSort::class) ?? ProjectFileSort::Added,
            $direction === '' ? null : $direction === 'desc',
        ];
    }

    /**
     * A project cannot default to the files view, so the query parameter alone decides.
     */
    private function showsFiles(): bool
    {
        return $this->query('view') === ProjectView::Files->value;
    }

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
     * @return array<string, string>
     */
    public function fieldFilters(): array
    {
        /** @var array<array-key, mixed> $filters */
        $filters = $this->input('field', []);

        $answers = [];

        foreach ($filters as $fieldId => $answer) {
            if (is_string($fieldId) && (is_string($answer) || is_numeric($answer)) && (string) $answer !== '') {
                $answers[$fieldId] = (string) $answer;
            }
        }

        return $answers;
    }

    /**
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
     * The calendar reuses the board's `expand` parameter for days.
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
