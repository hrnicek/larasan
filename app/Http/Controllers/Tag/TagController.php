<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tag;

use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Tag\Actions\CreateTag;
use App\Domain\Tag\Actions\DeleteTag;
use App\Domain\Tag\Actions\UpdateTag;
use App\Domain\Tag\Models\Tag;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Http\Requests\Tag\StoreTagRequest;
use App\Http\Requests\Tag\UpdateTagRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * A workspace's vocabulary. Every method answers with a redirect back: tags are made and edited
 * from wherever somebody is working rather than on a screen of their own.
 */
class TagController extends Controller
{
    public function store(StoreTagRequest $request, CreateTag $createTag): RedirectResponse
    {
        $workspace = ResolveCurrentWorkspace::from($request);

        if (! $workspace instanceof Workspace) {
            abort(404);
        }

        $createTag->handle(
            $workspace,
            $this->actor($request),
            (string) $request->string('name'),
            $this->color($request),
        );

        return back();
    }

    public function update(UpdateTagRequest $request, Tag $tag, UpdateTag $updateTag): RedirectResponse
    {
        $updateTag->handle(
            $tag,
            $this->actor($request),
            $request->has('name') ? (string) $request->string('name') : null,
            $this->color($request),
            // An explicit null clears the colour; an absent key leaves it alone (TASK-080-008's
            // rule, applied here).
            clearColor: $request->exists('color') && $request->input('color') === null,
        );

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
}
