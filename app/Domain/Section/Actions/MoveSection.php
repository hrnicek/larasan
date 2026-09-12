<?php

declare(strict_types=1);

namespace App\Domain\Section\Actions;

use App\Domain\Project\Models\Project;
use App\Domain\Section\Events\SectionMoved;
use App\Domain\Section\Exceptions\SectionException;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Ordering\PositionsNeedNormalisation;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class MoveSection
{
    public function __construct(private Dispatcher $events) {}

    /**
     * @param  Section|null  $after  the section this one goes behind, or null for the front
     */
    public function handle(Section $section, User $actor, ?Section $after): Section
    {
        if (! $section->project->allowsChangesBy($actor, Capability::SectionUpdate)) {
            throw SectionException::cannotManageSections();
        }

        if ($after !== null && $after->project_id !== $section->project_id) {
            throw SectionException::sectionBelongsToAnotherProject();
        }

        if ($after !== null && $after->is($section)) {
            throw SectionException::cannotFollowItself();
        }

        try {
            $moved = $this->place($section, $after);
        } catch (UniqueConstraintViolationException) {
            // A concurrent move took the same midpoint; retry against the refreshed order.
            $moved = $this->place($section->fresh() ?? $section, $after?->fresh());
        }

        if ($moved) {
            $this->events->dispatch(new SectionMoved($section->id, $section->project_id, $after?->id));
        }

        return $section->refresh();
    }

    /**
     * @return bool whether the section actually moved
     */
    private function place(Section $section, ?Section $after): bool
    {
        return DB::transaction(function () use ($section, $after): bool {
            $ordered = $this->lockedOrder($section->project);

            $target = $this->slotFor($ordered, $section, $after);

            if ($target === null) {
                return false;
            }

            try {
                $position = SparsePosition::between($target['before'], $target['after']);
            } catch (PositionsNeedNormalisation) {
                $ordered = $this->normalise($section->project);

                // normalise() rewrote this row through another instance, so save() would compare against a stale position.
                $section->refresh();

                $target = $this->slotFor($ordered, $section, $after);

                if ($target === null) {
                    return false;
                }

                $position = SparsePosition::between($target['before'], $target['after']);
            }

            $section->forceFill(['position' => $position])->save();

            return true;
        });
    }

    /**
     * @param  Collection<int, Section>  $ordered
     * @return array{before: int|null, after: int|null}|null
     */
    private function slotFor(Collection $ordered, Section $section, ?Section $after): ?array
    {
        $others = $ordered->reject(fn (Section $candidate): bool => $candidate->is($section))->values();

        $index = 0;

        if ($after !== null) {
            $anchor = $others->search(fn (Section $candidate): bool => $candidate->is($after));

            if ($anchor === false) {
                throw SectionException::sectionBelongsToAnotherProject();
            }

            $index = $anchor + 1;
        }

        $before = $index === 0 ? null : $others->get($index - 1)?->position;
        $next = $others->get($index)?->position;

        $current = $ordered->search(fn (Section $candidate): bool => $candidate->is($section));

        if ($current !== false && $this->alreadyBetween($ordered, $current, $before, $next)) {
            return null;
        }

        return ['before' => $before, 'after' => $next];
    }

    /**
     * @param  Collection<int, Section>  $ordered
     */
    private function alreadyBetween(Collection $ordered, int $current, ?int $before, ?int $next): bool
    {
        $previousPosition = $current === 0 ? null : $ordered->get($current - 1)?->position;
        $nextPosition = $ordered->get($current + 1)?->position;

        return $previousPosition === $before && $nextPosition === $next;
    }

    /**
     * @return Collection<int, Section>
     */
    private function lockedOrder(Project $project): Collection
    {
        return $project->sections()->lockForUpdate()->get();
    }

    /**
     * Rows are parked at negative positions first so the rewrite never violates
     * UNIQUE(project_id, position).
     *
     * @return Collection<int, Section>
     */
    private function normalise(Project $project): Collection
    {
        $ordered = $this->lockedOrder($project);

        foreach ($ordered as $index => $section) {
            $section->forceFill(['position' => SparsePosition::parking($index)])->save();
        }

        $spread = SparsePosition::spread($ordered->count());

        foreach ($ordered as $index => $section) {
            $section->forceFill(['position' => $spread[$index]])->save();
        }

        return $ordered;
    }
}
