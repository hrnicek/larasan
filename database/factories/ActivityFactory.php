<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Activity\Models\Activity;
use App\Domain\Shared\Enums\ActivityType;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    protected $model = Activity::class;

    /**
     * The subject is a task in the activity's own workspace: a line of history about a subject
     * somewhere else is a row the domain will never write.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'subject_type' => 'task',
            'subject_id' => fn (array $attributes): string => Task::factory()
                ->create(['workspace_id' => $attributes['workspace_id']])
                ->id,
            'actor_id' => fn (array $attributes): int => $this->member((string) $attributes['workspace_id'])->id,
            'type' => ActivityType::TaskCreated,
            'properties' => [],
        ];
    }

    private function member(string $workspaceId): User
    {
        $user = User::factory()->create();

        WorkspaceMembership::query()->create([
            'workspace_id' => $workspaceId,
            'user_id' => $user->id,
            'role' => WorkspaceRole::Member,
            'status' => WorkspaceMembershipStatus::Active,
            'joined_at' => now(),
        ]);

        return $user;
    }

    public function on(Model $subject, string $type = 'task'): self
    {
        return $this->state(fn (): array => [
            'subject_type' => $type,
            'subject_id' => $subject->getKey(),
            'workspace_id' => $subject->getAttribute('workspace_id'),
        ]);
    }

    public function by(User $actor): self
    {
        return $this->state(fn (): array => ['actor_id' => $actor->id]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function ofType(ActivityType $type, array $properties = []): self
    {
        return $this->state(fn (): array => ['type' => $type, 'properties' => $properties]);
    }
}
