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
     * Every nullable column is set explicitly: strict Eloquent throws on an attribute the
     * model never retrieved, so a factory that omits one hands each test a model that fails
     * on first read.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sentence = fake()->sentence();

        return [
            'project_id' => Project::factory(),
            'parent_id' => null,
            'title' => Str::headline(fake()->unique()->word()),
            'content' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $sentence]]],
                ],
            ],
            'excerpt' => $sentence,
            'position' => Page::POSITION_GAP,
            'version' => 1,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function in(Project $project): self
    {
        return $this->state(fn (): array => ['project_id' => $project->id]);
    }

    public function under(Page $parent): self
    {
        return $this->state(fn (): array => [
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
