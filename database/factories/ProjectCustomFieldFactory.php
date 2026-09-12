<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\ProjectCustomField;
use App\Domain\Project\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectCustomField>
 */
class ProjectCustomFieldFactory extends Factory
{
    protected $model = ProjectCustomField::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'custom_field_id' => fn (array $attributes): string => CustomField::factory()->create([
                'workspace_id' => Project::query()->whereKey($attributes['project_id'])->value('workspace_id'),
            ])->id,
            'position' => 1,
        ];
    }

    public function attaching(Project $project, CustomField $field, int $position = 1): self
    {
        return $this->state(fn (): array => [
            'project_id' => $project->id,
            'custom_field_id' => $field->id,
            'position' => $position,
        ]);
    }
}
