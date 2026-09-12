<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'parent_id' => null,
            'title' => Str::headline(fake()->unique()->word().' '.fake()->word().' '.fake()->word()),
            'description' => null,
            'priority' => TaskPriority::Medium,
            'due_at' => null,
            'completed_at' => null,
            'completed_by' => null,
            'assignee_id' => null,
            'created_by' => null,
        ];
    }

    public function in(Workspace $workspace): self
    {
        return $this->state(fn (): array => ['workspace_id' => $workspace->id]);
    }

    public function completed(?User $by = null): self
    {
        return $this->state(fn (): array => [
            'completed_at' => CarbonImmutable::now(),
            'completed_by' => ($by ?? User::factory()->create())->id,
        ]);
    }

    public function assignedTo(User $user): self
    {
        return $this->state(fn (): array => ['assignee_id' => $user->id]);
    }

    public function childOf(Task $parent): self
    {
        return $this->state(fn (): array => [
            'parent_id' => $parent->id,
            'workspace_id' => $parent->workspace_id,
        ]);
    }

    public function withPriority(TaskPriority $priority): self
    {
        return $this->state(fn (): array => ['priority' => $priority]);
    }

    public function dueAt(CarbonImmutable $due): self
    {
        return $this->state(fn (): array => ['due_at' => $due]);
    }
}
