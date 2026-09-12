<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Comment\Models\Comment;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    protected $model = Comment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'commentable_type' => 'task',
            'commentable_id' => fn (array $attributes): string => Task::factory()
                ->create(['workspace_id' => $attributes['workspace_id']])
                ->id,
            'author_id' => fn (array $attributes): int => $this->member((string) $attributes['workspace_id'])->id,
            'body' => fake()->sentence(),
            'edited_at' => null,
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
            'commentable_type' => $type,
            'commentable_id' => $subject->getKey(),
            'workspace_id' => $subject->getAttribute('workspace_id'),
        ]);
    }

    public function by(User $author): self
    {
        return $this->state(fn (): array => ['author_id' => $author->id]);
    }

    public function edited(): self
    {
        return $this->state(fn (): array => ['edited_at' => now()]);
    }
}
