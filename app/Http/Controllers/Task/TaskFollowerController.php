<?php

declare(strict_types=1);

namespace App\Http\Controllers\Task;

use App\Domain\Task\Actions\FollowTask;
use App\Domain\Task\Actions\UnfollowTask;
use App\Domain\Task\Models\Task;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TaskFollowerController extends Controller
{
    public function store(Request $request, Task $task, FollowTask $followTask): RedirectResponse
    {
        Gate::authorize('view', $task);

        $followTask->handle($task, $this->actor($request));

        return back();
    }

    /**
     * No `view` check: someone who has lost access must still be able to unfollow.
     */
    public function destroy(Request $request, Task $task, UnfollowTask $unfollowTask): RedirectResponse
    {
        $unfollowTask->handle($task, $this->actor($request));

        return back();
    }
}
