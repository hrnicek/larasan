<?php

declare(strict_types=1);

namespace App\Domain\Page\Actions;

use App\Domain\Page\Content\PageDocument;
use App\Domain\Page\Data\CreatePageData;
use App\Domain\Page\Events\PageCreated;
use App\Domain\Page\Exceptions\PageException;
use App\Domain\Page\Models\Page;
use App\Domain\Page\Queries\PageSubtree;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class CreatePage
{
    public function __construct(private Dispatcher $events, private PageSubtree $subtree) {}

    public function handle(Project $project, User $actor, CreatePageData $data, ?Page $parent = null): Page
    {
        if (! $project->allowsChangesBy($actor, Capability::PageCreate)) {
            throw PageException::cannotWritePages();
        }

        if ($parent !== null && $parent->project_id !== $project->id) {
            throw PageException::parentBelongsToAnotherProject();
        }

        if ($parent !== null && $this->subtree->depthOf($parent) >= Page::MAX_DEPTH) {
            throw PageException::nestedTooDeeply();
        }

        try {
            $page = $this->append($project, $actor, $data, $parent);
        } catch (UniqueConstraintViolationException) {
            // An empty level has no row to lock, so concurrent creates can still collide on the sibling constraint.
            $page = $this->append($project, $actor, $data, $parent);
        }

        $this->events->dispatch(new PageCreated($page->id, $project->id, $actor->id));

        return $page;
    }

    private function append(Project $project, User $actor, CreatePageData $data, ?Page $parent): Page
    {
        return DB::transaction(function () use ($project, $actor, $data, $parent): Page {
            $last = Page::query()
                ->where('project_id', $project->id)
                ->where('parent_id', $parent?->id)
                ->orderBy('position', 'desc')
                ->lockForUpdate()
                ->value('position');

            $page = new Page([
                'title' => $data->title,
                'content' => PageDocument::empty(),
                'excerpt' => null,
                'position' => SparsePosition::append($last === null ? null : (int) $last),
            ]);

            $page->workspace_id = $project->workspace_id;
            $page->project_id = $project->id;
            $page->parent_id = $parent?->id;
            $page->created_by = $actor->id;
            $page->updated_by = $actor->id;
            $page->save();

            return $page;
        });
    }
}
