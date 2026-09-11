<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskCollaborator;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskCollaborator>
 */
class TaskCollaboratorFactory extends Factory
{
    protected $model = TaskCollaborator::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'user_id' => User::factory(),
        ];
    }

    public function on(Task $task, User $user): self
    {
        return $this->state(fn (): array => [
            'task_id' => $task->id,
            'user_id' => $user->id,
        ]);
    }
}
