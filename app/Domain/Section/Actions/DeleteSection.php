<?php

declare(strict_types=1);

namespace App\Domain\Section\Actions;

use App\Domain\Section\Events\SectionDeleted;
use App\Domain\Section\Exceptions\SectionException;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a column must never delete what was in it (ADR-0004): the placements move to no
 * section, and the tasks themselves are untouched — they belong to the workspace, not to a
 * column somebody removed.
 *
 * The placements live in `task_project_memberships`, which Phase 060 creates. Until then
 * there is nothing to move, and TASK-050-013 lands that half against the same transaction.
 * The rule is stated here rather than remembered later.
 */
final readonly class DeleteSection
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Section $section, User $actor): void
    {
        if (! $section->project->allowsSectionChangesBy($actor, Capability::SectionDelete)) {
            throw SectionException::cannotManageSections();
        }

        $projectId = $section->project_id;
        $sectionId = $section->id;

        DB::transaction(function () use ($section): void {
            $section->delete();
        });

        $this->events->dispatch(new SectionDeleted($sectionId, $projectId, $actor->id));
    }
}
