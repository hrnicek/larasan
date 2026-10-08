<?php

declare(strict_types=1);

namespace App\Http\Controllers\Task;

use App\Concerns\OpensTaskPanel;
use App\Domain\Shared\Enums\MyTasksTab;
use App\Domain\Task\Queries\MyTasksQuery;
use App\Domain\Task\Queries\TaskDetailQuery;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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

        $tasks = $this->memoized(fn (): array => $myTasks($workspace, $actor, $tab, $page));

        return Inertia::render('my-tasks/Index', [
            'tasks' => fn (): array => $tasks()['tasks'],
            'meta' => fn (): array => $tasks()['meta'],
            'tabs' => array_column(MyTasksTab::cases(), 'value'),
            ...$this->taskPanelProps($request, $workspace, $actor, $detail),
        ]);
    }
}
