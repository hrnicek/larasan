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
     * Ids resolve within the task's workspace; names match case-insensitively, as the unique index does.
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
