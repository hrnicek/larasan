<?php

declare(strict_types=1);

namespace App\Domain\Search\Queries;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The projects a term finds, for the palette.
 *
 * Reach is `VisibleProjectsForUser` and nothing else — the same query the sidebar, the project
 * list and every task's chips already ask. A private project a non-member has never been given
 * is not returned even when the index holds it (ADR-0016).
 *
 * Archived projects are found and say so: a search that hid them would be hiding work rather
 * than tidying it, and "where did that project go" is a question people type into a search box.
 */
final readonly class ProjectResults
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

        $results = Project::search($term)
            ->where('workspace_id', $workspace->id)
            ->query(fn (Builder $projects): Builder => $projects->whereIn('projects.id', $visible))
            ->take($limit * self::CANDIDATES_PER_RESULT)
            ->get()
            ->take($limit)
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'color' => $project->color?->value,
                'icon' => $project->icon?->value,
                'archived' => $project->archived_at !== null,
            ]);

        return array_values($results->all());
    }
}
