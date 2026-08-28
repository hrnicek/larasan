<?php

declare(strict_types=1);

namespace App\Http\Controllers\Page;

use App\Domain\Page\Actions\CreatePage;
use App\Domain\Page\Actions\DeletePage;
use App\Domain\Page\Data\CreatePageData;
use App\Domain\Page\Models\Page;
use App\Domain\Page\Queries\ProjectPagesQuery;
use App\Domain\Project\Models\Project;
use App\Http\Controllers\Controller;
use App\Http\Requests\Page\StorePageRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A page's own screen, and the two operations that change which pages exist.
 *
 * Creating one lands **on** it rather than back on the tree: a page is created in order to be
 * written in, and a tree with a new "Untitled" row in it is one click short of the thing the
 * person asked for.
 */
class PageController extends Controller
{
    public function show(Request $request, Page $page, ProjectPagesQuery $pages): Response
    {
        Gate::authorize('view', $page);

        $project = $page->project;
        $actor = $this->actor($request);

        $page->load('editor:id,name');

        return Inertia::render('pages/Show', [
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'content' => $page->content,
                // What the editor saves against. A save carrying an older number is refused, so
                // this is the one number on the screen that has to be exact.
                'version' => $page->version,
                'updatedAt' => $page->updated_at?->toIso8601String(),
                // The name only. Sharing the model would ship whatever columns `users` has
                // (`docs/conventions/frontend.md`), and a page needs to say who wrote in it, not who they are.
                'updatedBy' => $page->editor?->name,
            ],
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'color' => $project->color?->value,
                'icon' => $project->icon?->value,
            ],
            // The same tree the project's pages view draws, so the sidebar here is the sidebar
            // there rather than a second answer to the same question.
            'pages' => $pages($project, $actor),
        ]);
    }

    public function store(StorePageRequest $request, Project $project, CreatePage $createPage): RedirectResponse
    {
        $parent = $request->string('parent')->value() ?: null;

        $page = $createPage->handle(
            $project,
            $this->actor($request),
            CreatePageData::titled($request->string('title')->value()),
            $parent === null ? null : Page::query()->where('project_id', $project->id)->findOrFail($parent),
        );

        return to_route('pages.show', $page);
    }

    public function destroy(Request $request, Page $page, DeletePage $deletePage): RedirectResponse
    {
        Gate::authorize('delete', $page);

        $deletePage->handle($page, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Page removed.')]);

        return back();
    }
}
