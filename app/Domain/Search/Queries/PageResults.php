<?php

declare(strict_types=1);

namespace App\Domain\Search\Queries;

use App\Domain\Page\Models\Page;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The pages a term finds.
 *
 * Reach is the project's, and nothing else: a page is inside a project, so the rows are hydrated
 * through `VisibleProjectsForUser` exactly as the tree and the project screen are (ADR-0016). A
 * page in a private project the actor was never given stays out of the answer even when the
 * index holds it — a stale index can make this answer shorter, never wider.
 *
 * Each result carries the project it was written in, because "Brief" is a title several projects
 * will have and the project is what tells them apart.
 */
final readonly class PageResults
{
    /** @see TaskResults::CANDIDATES_PER_RESULT */
    private const CANDIDATES_PER_RESULT = 4;

    public function __construct(private VisibleProjectsForUser $visibleProjects) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(Workspace $workspace, User $actor, string $term, int $limit = 5): array
    {
        $term = trim($term);

        if ($term === '' || $limit < 1) {
            return [];
        }

        $visible = $this->visibleProjects
            ->query($workspace, $actor, includeArchived: true)
            ->select('projects.id');

        $results = Page::search($term)
            ->where('workspace_id', $workspace->id)
            ->query(fn (Builder $pages): Builder => $pages
                ->whereIn('pages.project_id', $visible)
                ->with('project:id,name,slug,color,icon'))
            ->take($limit * self::CANDIDATES_PER_RESULT)
            ->get()
            ->take($limit)
            ->map(fn (Page $page): array => [
                'id' => $page->id,
                'title' => $page->title,
                'excerpt' => $page->excerpt,
                'project' => [
                    'id' => $page->project->id,
                    'name' => $page->project->name,
                    'slug' => $page->project->slug,
                    'color' => $page->project->color?->value,
                    'icon' => $page->project->icon?->value,
                ],
            ]);

        return array_values($results->all());
    }
}
