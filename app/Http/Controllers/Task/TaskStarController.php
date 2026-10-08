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

class TaskStarController extends Controller
{
    public function store(Request $request, Task $task, StarTask $starTask): RedirectResponse
    {
        Gate::authorize('view', $task);

        $starTask->handle($task, $this->actor($request));

        return back();
    }

    /**
     * No `view` check: someone who has lost access must still be able to unstar.
     */
    public function destroy(Request $request, Task $task, UnstarTask $unstarTask): RedirectResponse
    {
        $unstarTask->handle($task, $this->actor($request));

        return back();
    }
}
