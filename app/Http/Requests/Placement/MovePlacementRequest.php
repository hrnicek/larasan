<?php

declare(strict_types=1);

namespace App\Http\Requests\Placement;

use App\Domain\Placement\Data\PlacementTarget;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Section\Models\Section;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MovePlacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $placement = $this->placement();

        return $placement instanceof TaskProjectMembership
            && $this->user()?->can('update', $placement) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $placement = $this->placement();

        return [
            // Null is a column: the ungrouped bucket (ADR-0004), not the absence of an
            // answer. `sometimes` therefore cannot stand in for it — an omitted section and
            // an explicit null are different requests.
            'section' => [
                'present', 'nullable', 'uuid',
                Rule::exists('sections', 'id')->where(
                    fn (Builder $query): Builder => $query->where('project_id', $placement?->project_id),
                ),
            ],

            /*
             * The move is "place this after that one" (ADR-0009): there is no position field
             * to send. The anchor must be a card in the same column of the same project and
             * must not be the card being moved. The Action refuses all of it again — a
             * console command or a queued job arrives without a request — but a stale board
             * deserves a validation error rather than a domain exception.
             */
            'after' => [
                'nullable', 'uuid',
                Rule::exists('task_project_memberships', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('project_id', $placement?->project_id)
                        ->where(fn (Builder $column): Builder => $this->section() === null
                            ? $column->whereNull('section_id')
                            : $column->where('section_id', $this->section())),
                ),
                Rule::notIn([$placement?->id]),
            ],

            // Where in the column, when no card is named: the two ends. Meaningless
            // alongside an anchor, so sending both is a mistake rather than a precedence
            // rule nobody would remember.
            'at' => ['nullable', 'prohibits:after', Rule::in(['front', 'end'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'section.exists' => __('That section is not in this project.'),
            'after.exists' => __('That card is not in this column.'),
            'after.not_in' => __('A task cannot be placed after itself.'),
        ];
    }

    /**
     * The column the card is moving into, resolved inside the placement's own project so a
     * section id from elsewhere cannot be reached even if validation is bypassed.
     */
    public function targetSection(): ?Section
    {
        $section = $this->section();
        $placement = $this->placement();

        if ($section === null || ! $placement instanceof TaskProjectMembership) {
            return null;
        }

        return Section::query()
            ->whereKey($section)
            ->where('project_id', $placement->project_id)
            ->firstOrFail();
    }

    public function target(): PlacementTarget
    {
        $placement = $this->placement();
        $after = $this->string('after')->value() ?: null;

        if ($after !== null && $placement instanceof TaskProjectMembership) {
            return PlacementTarget::after(
                $placement->project->placements()->whereKey($after)->firstOrFail(),
            );
        }

        return $this->string('at')->value() === 'front'
            ? PlacementTarget::front()
            : PlacementTarget::end();
    }

    private function section(): ?string
    {
        return $this->string('section')->value() ?: null;
    }

    private function placement(): ?TaskProjectMembership
    {
        $placement = $this->route('placement');

        return $placement instanceof TaskProjectMembership ? $placement : null;
    }
}
