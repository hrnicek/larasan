<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tag;

use App\Domain\Shared\ValueObjects\AccentColor;
use App\Domain\Tag\Actions\AttachTagToTask;
use App\Domain\Tag\Actions\CreateTag;
use App\Domain\Tag\Actions\DetachTagFromTask;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tag\StoreTaskTagRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Putting a tag on a task and taking it off — an edit of the task, which is why the
 * authorization is `update` on the task rather than anything about tags.
 *
 * A tag can be named rather than pointed at, so the word somebody wanted and the task they wanted
 * it on are one request: leaving a task open to go and define a tag, and coming back to apply it,
 * is three screens for one thought.
 */
class TaskTagController extends Controller
{
    public function store(StoreTaskTagRequest $request, Task $task, AttachTagToTask $attach, CreateTag $createTag): RedirectResponse
    {
        $attach->handle($task, $this->tag($request, $task, $createTag), $this->actor($request));

        return back();
    }

    public function destroy(Request $request, Task $task, Tag $tag, DetachTagFromTask $detach): RedirectResponse
    {
        Gate::authorize('update', $task);

        $detach->handle($task, $tag, $this->actor($request));

        return back();
    }

    /**
     * The tag this request meant, made if it did not exist.
     *
     * An id is resolved inside the task's own workspace, so a tag from another tenant is a 404
     * before the Action has to refuse it — the Action still does, for callers that never pass
     * through here.
     *
     * A *name* that the vocabulary already holds attaches that tag rather than refusing as a
     * duplicate: somebody typing a word that exists means the word, and only the branch that
     * genuinely adds one asks `tag.manage`. The match is case-insensitive because the unique
     * index is.
     */
    private function tag(StoreTaskTagRequest $request, Task $task, CreateTag $createTag): Tag
    {
        if ($request->filled('tag')) {
            return Tag::query()
                ->whereKey((string) $request->string('tag'))
                ->where('workspace_id', $task->workspace_id)
                ->first() ?? abort(404);
        }

        $name = trim((string) $request->string('name'));

        $existing = Tag::query()
            ->where('workspace_id', $task->workspace_id)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();

        return $existing ?? $createTag->handle(
            $task->workspace,
            $this->actor($request),
            $name,
            AccentColor::tryFrom($request->string('color')->value()),
        );
    }
}
