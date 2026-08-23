<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Tag\Models\Tag;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    /**
     * The name is unique per workspace and unique *case-insensitively*, so a factory that used
     * a plain word would collide the moment a test made two tags.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->word().'-'.Str::lower(Str::random(6)),
            'color' => fake()->randomElement(ProjectColor::cases()),
        ];
    }

    public function in(Workspace $workspace): self
    {
        return $this->state(fn (): array => ['workspace_id' => $workspace->id]);
    }

    public function named(string $name): self
    {
        return $this->state(fn (): array => ['name' => $name]);
    }
}
