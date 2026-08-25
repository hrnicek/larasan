<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Page\Models\Page;
use App\Domain\Project\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    protected $model = Page::class;

    /**
     * Positions are unique among siblings, so a factory that always wrote the same one could
     * not make two pages in a project without a collision. Every page a run creates gets the
     * next slot; `at()` overrides it where a test cares about the exact number.
     */
    private static int $slot = 0;

    /**
     * Every nullable column is set explicitly: strict Eloquent throws on an attribute the
     * model never retrieved, so a factory that omits one hands each test a model that fails
     * on first read.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sentence = fake()->sentence();

        $project = Project::factory()->create();

        return [
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'parent_id' => null,
            'title' => Str::headline(fake()->unique()->word()),
            'content' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $sentence]]],
                ],
            ],
            'excerpt' => $sentence,
            'position' => Page::POSITION_GAP * ++self::$slot,
            'version' => 1,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function in(Project $project): self
    {
        return $this->state(fn (): array => [
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
        ]);
    }

    public function under(Page $parent): self
    {
        return $this->state(fn (): array => [
            'workspace_id' => $parent->workspace_id,
            'project_id' => $parent->project_id,
            'parent_id' => $parent->id,
        ]);
    }

    public function at(int $position): self
    {
        return $this->state(fn (): array => ['position' => $position]);
    }

    public function titled(string $title): self
    {
        return $this->state(fn (): array => ['title' => $title]);
    }
}
