<?php

declare(strict_types=1);

namespace App\Http\Controllers\Search;

use App\Concerns\OpensTaskPanel;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Search\Queries\SearchTasksQuery;
use App\Domain\Task\Queries\TaskDetailQuery;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Http\Requests\Search\SearchRequest;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    use OpensTaskPanel;

    public function index(SearchRequest $request, SearchTasksQuery $search, VisibleProjectsForUser $projects, TaskDetailQuery $detail): Response
    {
        $workspace = ResolveCurrentWorkspace::from($request);

        if (! $workspace instanceof Workspace) {
            abort(404);
        }

        $actor = $this->actor($request);

        $results = $this->memoized(fn (): array => $search(
            $workspace,
            $actor,
            $request->term(),
            max(1, (int) $request->integer('page', 1)),
            $request->filters(),
        ));

        return Inertia::render('search/Index', [
            'tasks' => fn (): array => $results()['tasks'],
            'meta' => fn (): array => $results()['meta'],
            'filters' => (object) $request->filters(),
            // Not `projects`: a page prop of that name would replace the shared sidebar prop.
            'filterProjects' => fn (): array => $projects($workspace, $actor)
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                ])
                ->values()
                ->all(),
            ...$this->taskPanelProps($request, $workspace, $actor, $detail),
        ]);
    }
}
