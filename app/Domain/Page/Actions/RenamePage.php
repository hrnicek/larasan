<?php

declare(strict_types=1);

namespace App\Domain\Page\Actions;

use App\Domain\Page\Events\PageUpdated;
use App\Domain\Page\Exceptions\PageException;
use App\Domain\Page\Models\Page;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The title, on its own.
 *
 * Separate from the document deliberately. The title is renamed from the tree while somebody
 * else may have the page open, and a rename that touched `version` would refuse that person's
 * next autosave over a change that did not touch a word they wrote.
 */
final readonly class RenamePage
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Page $page, User $actor, string $title): Page
    {
        if (! $page->project->allowsChangesBy($actor, Capability::PageUpdate)) {
            throw PageException::cannotWritePages();
        }

        $title = trim($title);

        // A page with no name is `Untitled`, not an empty row in the tree.
        $page->forceFill([
            'title' => $title === '' ? Page::UNTITLED : $title,
            'updated_by' => $actor->id,
        ])->save();

        $this->events->dispatch(new PageUpdated($page->id, $page->project_id, $actor->id));

        return $page;
    }
}
