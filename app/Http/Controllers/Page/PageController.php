<?php

declare(strict_types=1);

namespace App\Http\Controllers\Page;

use App\Domain\Page\Actions\CreatePage;
use App\Domain\Page\Actions\DeletePage;
use App\Domain\Page\Data\CreatePageData;
use App\Domain\Page\Models\Page;
use App\Domain\Project\Models\Project;
use App\Http\Controllers\Controller;
use App\Http\Requests\Page\StorePageRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Starting and removing a page. Both answer with a redirect: the tree the actor is looking at
 * is what changes, and it is drawn by whichever screen they were on.
 */
class PageController extends Controller
{
    public function store(StorePageRequest $request, Project $project, CreatePage $createPage): RedirectResponse
    {
        $parent = $request->string('parent')->value() ?: null;

        $createPage->handle(
            $project,
            $this->actor($request),
            CreatePageData::titled($request->string('title')->value()),
            $parent === null ? null : Page::query()->where('project_id', $project->id)->findOrFail($parent),
        );

        return back();
    }

    public function destroy(Request $request, Page $page, DeletePage $deletePage): RedirectResponse
    {
        Gate::authorize('delete', $page);

        $deletePage->handle($page, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Page removed.')]);

        return back();
    }
}
