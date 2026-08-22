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
     * Every nullable column is set explicitly: strict Eloquent throws on an attribute the
     * model never retrieved, so a factory that omits one hands each test a model that
     * fails on first read.
     *
     * The default is ungrouped, which is what attaching a task to a project produces
     * before anybody drags it into a column.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            /*
             * Resolved from the task rather than made independently: both ends of a
             * placement must be in one workspace (ADR-0003), and a factory that produces
             * two unrelated ones hands every test a row the domain would have refused.
             */
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
