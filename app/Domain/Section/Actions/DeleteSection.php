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
 * Cards are moved explicitly because the nullOnDelete foreign key keeps their positions,
 * which would collide with cards already in the ungrouped bucket. See ADR-0004.
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

    private function emptyIntoTheUngroupedBucket(Section $section): void
    {
        $tail = $section->project->placements()
            ->whereNull('section_id')
            ->reorder('position', 'desc')
            ->lockForUpdate()
            ->value('position');

        $table = $section->placements()->getModel()->getTable();

        DB::update(<<<SQL
            update {$table} as target
            set section_id = null,
                position = ? + ordered.rank * ?,
                updated_at = ?
            from (
                select id, row_number() over (order by position, id) as rank
                from {$table}
                where section_id = ?
            ) as ordered
            where target.id = ordered.id
        SQL, [$tail === null ? 0 : (int) $tail, SparsePosition::GAP, now(), $section->id]);
    }
}
