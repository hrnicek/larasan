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

/**
 * One screen, one address: a search is a link, so the term and the filters live in the query
 * string and a reload lands on the same results.
 */
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
            // Two keys of one read — an engine round trip — resolved only when a response
            // carries them: opening a result's panel does not search again.
            'tasks' => fn (): array => $results()['tasks'],
            'meta' => fn (): array => $results()['meta'],
            'filters' => (object) $request->filters(),
            /*
             * The projects to narrow by: the ones this actor can open, so the filter cannot name
             * a project search would never return anything from. Named apart from the shared
             * `projects` prop the sidebar reads — a page prop of the same name replaces it, and
             * the sidebar would quietly render this shorter, colourless list instead.
             */
            'filterProjects' => fn (): array => $projects($workspace, $actor)
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                ])
                ->values()
                ->all(),
            /*
             * A result opens the panel at this screen's own address, so the search — the term,
             * the filters, the page — is still there behind it and still there when it closes.
             * `members` comes from here too, which is why the list this screen used to build
             * itself is gone: one place, four screens.
             */
            ...$this->taskPanelProps($request, $workspace, $actor, $detail),
        ]);
    }
}
