<?php

declare(strict_types=1);

namespace App\Http\Controllers\Task;

use App\Concerns\OpensTaskPanel;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\MyTasksTab;
use App\Domain\Task\Queries\MyTasksQuery;
use App\Domain\Task\Queries\TaskDetailQuery;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What one person is responsible for, in the workspace they are standing in.
 *
 * The tab and the page are query parameters rather than state the server remembers: a link to
 * My Tasks has to carry the view it was read in, and a refresh has to land back on it.
 */
class MyTasksController extends Controller
{
    use OpensTaskPanel;

    public function index(Request $request, MyTasksQuery $myTasks, TaskDetailQuery $detail): Response
    {
        $workspace = ResolveCurrentWorkspace::from($request);
        $actor = $this->actor($request);

        if (! $workspace instanceof Workspace) {
            abort(404);
        }

        $tab = MyTasksTab::tryFrom((string) $request->query('tab')) ?? MyTasksTab::Today;
        $page = max(1, (int) $request->query('page', '1'));

        return Inertia::render('my-tasks/Index', [
            ...$myTasks($workspace, $actor, $tab, $page),
            'tabs' => array_column(MyTasksTab::cases(), 'value'),
            'can' => [
                // One answer for the screen: reach is not in question here, since every task on
                // it is one this person was given.
                'updateTask' => $workspace->membershipFor($actor)?->allows(Capability::TaskUpdate) === true,
            ],
            /*
             * A row here opens the same panel the project screen opens, at this screen's own
             * address. Reach is still asked for: being assigned a task is not the same as being
             * able to read it, and a task can be unassigned or moved out of somebody's projects
             * while their tab is still open.
             */
            ...$this->taskPanelProps($workspace, $this->openTaskPanel($request, $workspace, $actor, $detail)),
        ]);
    }
}
