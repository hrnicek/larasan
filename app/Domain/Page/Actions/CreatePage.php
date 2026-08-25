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

/**
 * Start a document, at the end of its siblings.
 *
 * A page begins empty rather than with a template: the first thing somebody does with a new
 * page is type into it, and a paragraph they have to delete first is in the way.
 */
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
            /*
             * Two people started a page under the same parent at the same moment and computed
             * the same slot. The sibling constraint turned that into an error rather than two
             * pages in one place; this one reads the tail again and appends after the winner.
             */
            $page = $this->append($project, $actor, $data, $parent);
        }

        $this->events->dispatch(new PageCreated($page->id, $project->id, $actor->id));

        return $page;
    }

    private function append(Project $project, User $actor, CreatePageData $data, ?Page $parent): Page
    {
        return DB::transaction(function () use ($project, $actor, $data, $parent): Page {
            /*
             * The tail row is read and locked inside the transaction, so a second append waits
             * rather than computing the same slot — the arrangement `CreateSection` uses, with
             * the same caveat: an empty level has no row to lock, and the unique constraint is
             * what catches the collision that leaves.
             */
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
