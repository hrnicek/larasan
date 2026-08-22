<?php

declare(strict_types=1);

namespace App\Domain\Section\Actions;

use App\Domain\Section\Events\SectionDeleted;
use App\Domain\Section\Exceptions\SectionException;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a column must never delete what was in it (ADR-0004): the cards move to the
 * ungrouped bucket, and the tasks themselves are untouched — they belong to the workspace,
 * not to a column somebody removed.
 *
 * They are moved rather than left to the foreign key. `section_id` is `nullOnDelete`, which
 * keeps the promise for a delete that never comes through here, but it nulls the column
 * while keeping the position — and a position is only unique within its bucket, so a card
 * carrying `65536` out of a column meets whatever is already sitting at `65536` in the
 * ungrouped bucket. The slot guard then refuses the whole delete. Appending each card to the
 * end of the bucket, in the same transaction, is what makes the operation safe.
 */
final readonly class DeleteSection
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Section $section, User $actor): void
    {
        if (! $section->project->allowsChangesBy($actor, Capability::SectionDelete)) {
            throw SectionException::cannotManageSections();
        }

        $projectId = $section->project_id;
        $sectionId = $section->id;

        DB::transaction(function () use ($section): void {
            $this->emptyIntoTheUngroupedBucket($section);

            $section->delete();
        });

        $this->events->dispatch(new SectionDeleted($sectionId, $projectId, $actor->id));
    }

    /**
     * The column's cards, in the order they were in, appended to the end of the project's
     * ungrouped bucket. Both ends are locked inside the transaction so a concurrent move
     * cannot take a slot between the read and the write.
     */
    private function emptyIntoTheUngroupedBucket(Section $section): void
    {
        $tail = $section->project->placements()
            ->whereNull('section_id')
            ->reorder('position', 'desc')
            ->lockForUpdate()
            ->value('position');

        $last = $tail === null ? null : (int) $tail;

        foreach ($section->placements()->lockForUpdate()->get() as $placement) {
            $last = SparsePosition::append($last);

            $placement->forceFill(['section_id' => null, 'position' => $last])->save();
        }
    }
}
