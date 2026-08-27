<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tag;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Tag\Actions\CreateTag;
use App\Domain\Tag\Actions\DeleteTag;
use App\Domain\Tag\Actions\UpdateTag;
use App\Domain\Tag\Exceptions\TagException;
use App\Domain\Tag\Models\Tag;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Http\Requests\Tag\StoreTagRequest;
use App\Http\Requests\Tag\UpdateTagRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A workspace's vocabulary.
 *
 * The writes answer with a redirect back, because a tag is made from wherever somebody is
 * working — the task detail invents one without leaving the task. The list is a settings screen
 * beside fields and members for the same reason that one is: renaming a word changes what it
 * means everywhere it is already applied, which is `tag.manage` rather than an edit of anything.
 */
class TagController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->current($request);

        Gate::authorize('view', $workspace);

        return Inertia::render('settings/Tags', [
            'tags' => $workspace->tags()
                // Counted rather than listed: the screen says what a deletion costs, and naming
                // every task would make the row a paragraph.
                ->withCount('tasks')
                ->orderBy('name')
                ->get()
                ->map(fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color?->value,
                    'taskCount' => (int) $tag->getAttribute('tasks_count'),
                ])
                ->all(),
            'can' => [
                'manage' => $request->user()?->can(Capability::TagManage->value, $workspace) ?? false,
            ],
        ]);
    }

    public function store(StoreTagRequest $request, CreateTag $createTag): RedirectResponse
    {
        $workspace = $this->current($request);

        $this->translating(fn () => $createTag->handle(
            $workspace,
            $this->actor($request),
            (string) $request->string('name'),
            $this->color($request),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tag created.')]);

        return back();
    }

    public function update(UpdateTagRequest $request, Tag $tag, UpdateTag $updateTag): RedirectResponse
    {
        $this->translating(fn () => $updateTag->handle(
            $tag,
            $this->actor($request),
            $request->has('name') ? (string) $request->string('name') : null,
            $this->color($request),
            // An explicit null clears the colour; an absent key leaves it alone (TASK-080-008's
            // rule, applied here).
            clearColor: $request->exists('color') && $request->input('color') === null,
        ));

        return back();
    }

    public function destroy(Request $request, Tag $tag, DeleteTag $deleteTag): RedirectResponse
    {
        Gate::authorize('tag.manage', $tag->workspace);

        $deleteTag->handle($tag, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tag deleted.')]);

        return back();
    }

    private function color(Request $request): ?ProjectColor
    {
        $color = $request->input('color');

        return is_string($color) ? ProjectColor::tryFrom($color) : null;
    }

    private function current(Request $request): Workspace
    {
        return ResolveCurrentWorkspace::from($request) ?? abort(404);
    }

    /**
     * A name already taken is an error on the box somebody can act on rather than a toast they
     * have to read and then find. The unique index is what answers, because two people can
     * invent "Bug" in the same second — so it cannot be pre-checked in the FormRequest.
     */
    private function translating(callable $operation): void
    {
        try {
            $operation();
        } catch (TagException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }
    }
}
