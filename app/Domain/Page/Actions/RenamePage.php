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
 * Does not touch `version`, so a rename never rejects a concurrent content save.
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

        $page->forceFill([
            'title' => $title === '' ? Page::UNTITLED : $title,
            'updated_by' => $actor->id,
        ])->save();

        $this->events->dispatch(new PageUpdated($page->id, $page->project_id, $actor->id));

        return $page;
    }
}
