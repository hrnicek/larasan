<?php

declare(strict_types=1);

namespace App\Domain\Section\Actions;

use App\Domain\Project\Models\Project;
use App\Domain\Section\Events\SectionDeleted;
use App\Domain\Section\Exceptions\SectionException;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cards are moved explicitly because the nullOnDelete foreign key keeps their positions,
 * which would collide with cards already in the ungrouped bucket. See ADR-0004.
 */
final readonly class DeleteSection
{
    private const int ATTEMPTS = 3;

    public function __construct(private Dispatcher $events) {}

    public function handle(Section $section, User $actor): void
    {
        if (! $section->project->allowsChangesBy($actor, Capability::SectionDelete)) {
            throw SectionException::cannotManageSections();
        }

        $projectId = $section->project_id;
        $sectionId = $section->id;

        try {
            $this->delete($section);
        } catch (UniqueConstraintViolationException) {
            // A concurrent append took a slot at the end of the ungrouped bucket; the retry reads the new end.
            $this->delete($section);
        }

        $this->events->dispatch(new SectionDeleted($sectionId, $projectId, $actor->id));
    }

    private function delete(Section $section): void
    {
        DB::transaction(function () use ($section): void {
            // The same lock MoveTaskInProject takes first, so a move and a delete queue rather than deadlock.
            Project::query()->whereKey($section->project_id)->lock('for no key update')->value('id');

            $this->emptyIntoTheUngroupedBucket($section);

            $section->delete();
        }, self::ATTEMPTS);
    }

    private function emptyIntoTheUngroupedBucket(Section $section): void
    {
        $tail = $section->project->placements()->whereNull('section_id')->max('position');

        $table = $section->placements()->getModel()->getTable();

        DB::update(<<<SQL
            update {$table} as target
            set section_id = null,
                position = ? + ordered.rank * ?,
                updated_at = ?
            from (
                select id, row_number() over (order by position, id) as rank
                from {$table}
                where project_id = ? and section_id = ?
            ) as ordered
            where target.id = ordered.id
        SQL, [$tail === null ? 0 : (int) $tail, SparsePosition::GAP, now(), $section->project_id, $section->id]);
    }
}
