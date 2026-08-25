<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Search\Models\RecentItem;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<RecentItem>
 */
class RecentItemFactory extends Factory
{
    protected $model = RecentItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => Workspace::factory(),
            'subject_type' => 'task',
            'subject_id' => Task::factory(),
            'opened_at' => now(),
        ];
    }

    public function ownedBy(User $user): self
    {
        return $this->state(fn (): array => ['user_id' => $user->id]);
    }

    public function in(Workspace $workspace): self
    {
        return $this->state(fn (): array => ['workspace_id' => $workspace->id]);
    }

    public function of(Model $subject): self
    {
        return $this->state(fn (): array => [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }
}
