<?php

declare(strict_types=1);

namespace App\Domain\Page\Actions;

use App\Domain\Page\Content\PageDocument;
use App\Domain\Page\Data\SavePageContentData;
use App\Domain\Page\Events\PageUpdated;
use App\Domain\Page\Exceptions\PageException;
use App\Domain\Page\Models\Page;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Write the document.
 *
 * The save carries the version the writer's editor last read. A save carrying an older number
 * is refused rather than applied: until pages are edited together (ADR-0017), the honest
 * failure is telling somebody their copy is stale, and the dishonest one is quietly replacing
 * the paragraph a colleague wrote thirty seconds ago.
 *
 * The comparison and the increment happen in one transaction with the row locked, so two saves
 * arriving together cannot both read the same version and both think they won.
 */
final readonly class SavePageContent
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Page $page, User $actor, SavePageContentData $data): Page
    {
        if (! $page->project->allowsChangesBy($actor, Capability::PageUpdate)) {
            throw PageException::cannotWritePages();
        }

        $content = PageDocument::sanitize($data->content);

        $saved = DB::transaction(function () use ($page, $actor, $data, $content): Page {
            $current = Page::query()->lockForUpdate()->findOrFail($page->id);

            if ($current->version !== $data->version) {
                throw PageException::changedElsewhere();
            }

            $current->forceFill([
                'content' => $content,
                'excerpt' => PageDocument::excerpt($content),
                'version' => $current->version + 1,
                'updated_by' => $actor->id,
            ])->save();

            return $current;
        });

        $this->events->dispatch(new PageUpdated($saved->id, $saved->project_id, $actor->id));

        return $saved;
    }
}
