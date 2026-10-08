<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Ordering\SparsePosition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Section>
 */
class SectionFactory extends Factory
{
    protected $model = Section::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => Str::headline(fake()->unique()->word()),
            'color' => null,
        ];
    }

    public function configure(): static
    {
        // A batch is made in full before any row is saved, so the database alone cannot see its siblings.
        /** @var array<string, int> $made */
        $made = [];

        return $this->afterMaking(function (Section $section) use (&$made): void {
            if (isset($section->position)) {
                return;
            }

            $last = Section::query()->where('project_id', $section->project_id)->max('position');

            $section->position = $made[$section->project_id] = SparsePosition::append(
                max((int) $last, $made[$section->project_id] ?? 0),
            );
        });
    }

    public function in(Project $project): self
    {
        return $this->state(fn (): array => ['project_id' => $project->id]);
    }

    public function at(int $position): self
    {
        return $this->state(fn (): array => ['position' => $position]);
    }
}
