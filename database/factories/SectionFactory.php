<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
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
            'position' => Section::POSITION_GAP,
        ];
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
