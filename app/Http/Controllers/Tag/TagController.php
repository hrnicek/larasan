<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tag;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\ValueObjects\AccentColor;
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

class TagController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->current($request);

        Gate::authorize('view', $workspace);

        return Inertia::render('settings/Tags', [
            'tags' => $workspace->tags()
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

    private function color(Request $request): ?AccentColor
    {
        return AccentColor::tryFrom($request->string('color')->value());
    }

    private function current(Request $request): Workspace
    {
        return ResolveCurrentWorkspace::from($request) ?? abort(404);
    }

    /**
     * The unique index enforces names, since a FormRequest pre-check would race concurrent requests.
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
