<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::headline(fake()->unique()->word().' '.fake()->word());

        return [
            'workspace_id' => Workspace::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => null,
            'color' => null,
            'icon' => null,
            'owner_id' => null,
            'created_by' => null,
            'default_view' => ProjectDefaultView::List,
            'visibility' => ProjectVisibility::Workspace,
            'start_date' => null,
            'due_date' => null,
            'archived_at' => null,
        ];
    }

    public function in(Workspace $workspace): self
    {
        return $this->state(fn (): array => ['workspace_id' => $workspace->id]);
    }

    public function ownedBy(User $user): self
    {
        return $this->state(fn (): array => ['owner_id' => $user->id, 'created_by' => $user->id]);
    }

    public function private(): self
    {
        return $this->state(fn (): array => ['visibility' => ProjectVisibility::Private]);
    }

    public function archived(): self
    {
        return $this->state(fn (): array => ['archived_at' => CarbonImmutable::now()]);
    }
}
