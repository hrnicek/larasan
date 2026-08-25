<?php

declare(strict_types=1);

namespace App\Domain\Page\Queries;

use App\Domain\Page\Models\Page;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;
use App\Models\User as Actor;
use Illuminate\Database\Eloquent\Collection;

/**
 * A project's documents, as the tree they are.
 *
 * One query, not one per level: every page of the project is read once and assembled in memory,
 * because a tree bounded at `Page::MAX_DEPTH` is small and a query per level is a depth-shaped
 * N+1 (the shape TASK-070-015 took off the board).
 *
 * The documents themselves are not read. A tree draws titles and the first line of each page,
 * and `content` is the one column here that can be a hundred kilobytes — selecting it to render
 * a sidebar would make the list cost what the pages cost.
 *
 * Authorization is computed once, from the project, rather than asked per row: every page in a
 * project answers the same way, so `PagePolicy` per row would be an N+1 with a different name.
 */
final readonly class ProjectPagesQuery
{
    /**
     * @return array{
     *     tree: list<array<string, mixed>>,
     *     can: array{createPage: bool, updatePage: bool, deletePage: bool},
     * }
     */
    public function __invoke(Project $project, User $actor): array
    {
        /** @var Collection<int, Page> $pages */
        $pages = Page::query()
            ->select(['id', 'parent_id', 'title', 'excerpt', 'position', 'updated_at', 'updated_by'])
            ->where('project_id', $project->id)
            ->orderBy('position')
            ->get();

        return [
            'tree' => $this->branch($pages, null),
            'can' => [
                'createPage' => $this->allows($project, $actor, Capability::PageCreate),
                'updatePage' => $this->allows($project, $actor, Capability::PageUpdate),
                'deletePage' => $this->allows($project, $actor, Capability::PageDelete),
            ],
        ];
    }

    /**
     * @param  Collection<int, Page>  $pages
     * @return list<array<string, mixed>>
     */
    private function branch(Collection $pages, ?string $parentId): array
    {
        return array_values($pages
            ->where('parent_id', $parentId)
            ->map(fn (Page $page): array => [
                'id' => $page->id,
                'parentId' => $page->parent_id,
                'title' => $page->title,
                'excerpt' => $page->excerpt,
                'updatedAt' => $page->updated_at?->toIso8601String(),
                'children' => $this->branch($pages, $page->id),
            ])
            ->values()
            ->all());
    }

    private function allows(Project $project, Actor $actor, Capability $capability): bool
    {
        return $project->allowsChangesBy($actor, $capability);
    }
}
