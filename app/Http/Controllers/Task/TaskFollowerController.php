<?php

declare(strict_types=1);

namespace App\Http\Controllers\Task;

use App\Domain\Task\Actions\FollowTask;
use App\Domain\Task\Actions\UnfollowTask;
use App\Domain\Task\Models\Task;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Watching a task, and stopping.
 *
 * The actor follows on their own behalf and nobody else's: subscribing somebody else is a
 * different operation with a different question to answer, and it does not exist yet. Both
 * methods answer with a redirect back, because a follow changes a control rather than a
 * screen.
 */
class TaskFollowerController extends Controller
{
    public function store(Request $request, Task $task, FollowTask $followTask): RedirectResponse
    {
        Gate::authorize('view', $task);

        $followTask->handle($task, $this->actor($request));

        return back();
    }

    /**
     * No `view` check here: somebody who has lost access must still be able to stop being
     * notified, which is the Action's rule and the route's binding is enough to reach it.
     */
    public function destroy(Request $request, Task $task, UnfollowTask $unfollowTask): RedirectResponse
    {
        $unfollowTask->handle($task, $this->actor($request));

        return back();
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : abort(403);
    }
}
