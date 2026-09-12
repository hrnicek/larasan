<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Ordering\SparsePosition;
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
        // Closures resolve in key order: a project given by a state picks the task's workspace, otherwise the task picks the project's.
        return [
            'task_id' => fn (array $attributes): string => Task::factory()
                ->createOne(is_string($attributes['project_id'])
                    ? ['workspace_id' => Project::query()->whereKey($attributes['project_id'])->value('workspace_id')]
                    : [])
                ->id,
            'project_id' => fn (array $attributes): string => Project::factory()
                ->createOne(['workspace_id' => Task::query()->whereKey($attributes['task_id'])->value('workspace_id')])
                ->id,
            'section_id' => null,
        ];
    }

    public function configure(): static
    {
        // A batch is made in full before any row is saved, so the database alone cannot see its siblings.
        /** @var array<string, int> $made */
        $made = [];

        return $this->afterMaking(function (TaskProjectMembership $placement) use (&$made): void {
            if (isset($placement->position)) {
                return;
            }

            $bucket = $placement->project_id.'/'.$placement->section_id;

            $last = TaskProjectMembership::query()
                ->where('project_id', $placement->project_id)
                ->where('section_id', $placement->section_id)
                ->max('position');

            $placement->position = $made[$bucket] = SparsePosition::append(max((int) $last, $made[$bucket] ?? 0));
        });
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
