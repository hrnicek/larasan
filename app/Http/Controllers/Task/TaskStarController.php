<?php

declare(strict_types=1);

namespace App\Http\Controllers\Task;

use App\Domain\Task\Actions\StarTask;
use App\Domain\Task\Actions\UnstarTask;
use App\Domain\Task\Models\Task;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Starring a task, and stopping.
 *
 * The actor stars on their own behalf and nobody else's. Both methods answer with a redirect
 * back, because a star changes a control rather than a screen — and with no toast, because the
 * control itself says which way it now points.
 */
class TaskStarController extends Controller
{
    public function store(Request $request, Task $task, StarTask $starTask): RedirectResponse
    {
        Gate::authorize('view', $task);

        $starTask->handle($task, $this->actor($request));

        return back();
    }

    /**
     * No `view` check here: somebody who has lost access must still be able to clear the task out
     * of their own Starred tab, which is the Action's rule.
     */
    public function destroy(Request $request, Task $task, UnstarTask $unstarTask): RedirectResponse
    {
        $unstarTask->handle($task, $this->actor($request));

        return back();
    }
}
