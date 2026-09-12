<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Task\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskProjectMembership>
 */
class TaskProjectMembershipFactory extends Factory
{
    protected $model = TaskProjectMembership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'project_id' => fn (array $attributes): string => Project::factory()
                ->createOne(['workspace_id' => Task::query()->whereKey($attributes['task_id'])->value('workspace_id')])
                ->id,
            'section_id' => null,
            'position' => TaskProjectMembership::POSITION_GAP,
        ];
    }

    public function placing(Task $task, Project $project): self
    {
        return $this->state(fn (): array => [
            'task_id' => $task->id,
            'project_id' => $project->id,
        ]);
    }

    public function inSection(Section $section): self
    {
        return $this->state(fn (): array => [
            'project_id' => $section->project_id,
            'section_id' => $section->id,
        ]);
    }

    public function at(int $position): self
    {
        return $this->state(fn (): array => ['position' => $position]);
    }
}
