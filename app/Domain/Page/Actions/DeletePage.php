<?php

declare(strict_types=1);

namespace App\Domain\Page\Actions;

use App\Domain\Page\Events\PageDeleted;
use App\Domain\Page\Exceptions\PageException;
use App\Domain\Page\Models\Page;
use App\Domain\Page\Queries\PageSubtree;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Descendants are deleted explicitly because the `parent_id` cascade does not fire on soft deletes.
 */
final readonly class DeletePage
{
    public function __construct(private Dispatcher $events, private PageSubtree $subtree) {}

    public function handle(Page $page, User $actor): void
    {
        if (! $page->project->allowsChangesBy($actor, Capability::PageDelete)) {
            throw PageException::cannotWritePages();
        }

        $descendants = $this->subtree->idsUnder($page);
        $projectId = $page->project_id;
        $pageId = $page->id;

        DB::transaction(function () use ($page, $descendants): void {
            if ($descendants !== []) {
                Page::query()->whereIn('id', $descendants)->delete();
            }

            $page->delete();
        });

        $this->events->dispatch(new PageDeleted($pageId, $projectId, $actor->id, $descendants));
    }
}
