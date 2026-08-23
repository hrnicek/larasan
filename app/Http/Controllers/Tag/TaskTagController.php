<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tag;

use App\Domain\Tag\Actions\AttachTagToTask;
use App\Domain\Tag\Actions\DetachTagFromTask;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Putting a tag on a task and taking it off — an edit of the task, which is why the
 * authorization is `update` on the task rather than anything about tags.
 */
class TaskTagController extends Controller
{
    public function store(Request $request, Task $task, AttachTagToTask $attach): RedirectResponse
    {
        Gate::authorize('update', $task);

        $attach->handle($task, $this->tag($request, $task), $this->actor($request));

        return back();
    }

    public function destroy(Request $request, Task $task, Tag $tag, DetachTagFromTask $detach): RedirectResponse
    {
        Gate::authorize('update', $task);

        $detach->handle($task, $tag, $this->actor($request));

        return back();
    }

    /**
     * Resolved inside the task's own workspace, so a tag from another tenant is a 404 before the
     * Action has to refuse it — the Action still does, for callers that never pass through here.
     */
    private function tag(Request $request, Task $task): Tag
    {
        return Tag::query()
            ->whereKey((string) $request->string('tag'))
            ->where('workspace_id', $task->workspace_id)
            ->first() ?? abort(404);
    }
}
