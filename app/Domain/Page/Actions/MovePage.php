<?php

declare(strict_types=1);

namespace App\Domain\Page\Actions;

use App\Domain\Page\Events\PageMoved;
use App\Domain\Page\Exceptions\PageException;
use App\Domain\Page\Models\Page;
use App\Domain\Page\Queries\PageSubtree;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Ordering\PositionsNeedNormalisation;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Moving a page in the tree: under a parent, or to the root, and after one of its new
 * siblings. Expressed as "put this page after that one" rather than as a position (ADR-0009),
 * so a stale tree cannot compute a slot from what it last saw.
 */
final readonly class MovePage
{
    public function __construct(private Dispatcher $events, private PageSubtree $subtree) {}

    /**
     * @param  Page|null  $parent  the page this one goes inside, or null for the root
     * @param  Page|null  $after  the sibling it goes behind, or null for the front
     */
    public function handle(Page $page, User $actor, ?Page $parent, ?Page $after): Page
    {
        if (! $page->project->allowsChangesBy($actor, Capability::PageUpdate)) {
            throw PageException::cannotWritePages();
        }

        $this->guardParent($page, $parent);
        $this->guardAnchor($page, $parent, $after);

        try {
            $this->place($page, $parent, $after);
        } catch (UniqueConstraintViolationException) {
            /*
             * Two moves computed the same midpoint under the same parent. The sibling
             * constraint made that an error instead of two pages in one slot; this one reads
             * the order again, which now contains the winner, and places itself relative to it.
             */
            $this->place($page->refresh(), $parent?->fresh(), $after?->fresh());
        }

        $page->refresh();

        $this->events->dispatch(new PageMoved($page->id, $page->project_id, $page->parent_id));

        return $page;
    }

    private function guardParent(Page $page, ?Page $parent): void
    {
        if ($parent === null) {
            return;
        }

        if ($parent->project_id !== $page->project_id) {
            throw PageException::parentBelongsToAnotherProject();
        }

        // A page inside itself is a subtree that has left the tree: nothing would list it, and
        // deleting the project would be the only way to reach it again.
        if ($parent->is($page) || in_array($parent->id, $this->subtree->idsUnder($page), true)) {
            throw PageException::cannotContainItself();
        }

        if ($this->subtree->depthOf($parent) + $this->subtree->heightOf($page) > Page::MAX_DEPTH) {
            throw PageException::nestedTooDeeply();
        }
    }

    private function guardAnchor(Page $page, ?Page $parent, ?Page $after): void
    {
        if ($after === null) {
            return;
        }

        if ($after->is($page)) {
            throw PageException::anchorIsNotASibling();
        }

        // The anchor has to be where the page is going, or "after" means nothing. A tree that
        // sends one from somewhere else is stale, and placing at the front would be a guess.
        if ($after->project_id !== $page->project_id || $after->parent_id !== $parent?->id) {
            throw PageException::anchorIsNotASibling();
        }
    }

    private function place(Page $page, ?Page $parent, ?Page $after): void
    {
        DB::transaction(function () use ($page, $parent, $after): void {
            $siblings = $this->lockedSiblings($page, $parent);
            $slot = $this->slotFor($siblings, $page, $after);

            try {
                $position = SparsePosition::between($slot['before'], $slot['after']);
            } catch (PositionsNeedNormalisation) {
                /*
                 * The neighbours have closed up, so there is no midpoint left to take. The
                 * level is respread inside this transaction and the slot recomputed from the
                 * new positions — normalisation is the exception, not the steady state.
                 */
                $siblings = $this->normalise($page, $parent);
                $slot = $this->slotFor($siblings, $page, $after);
                $position = SparsePosition::between($slot['before'], $slot['after']);
            }

            $page->forceFill([
                'parent_id' => $parent?->id,
                'position' => $position,
            ])->save();
        });
    }

    /**
     * The level the page is moving into, locked and in order, so a second move waits instead
     * of reading the same neighbours. The page itself is excluded: it is being placed, not
     * placed against.
     *
     * @return Collection<int, Page>
     */
    private function lockedSiblings(Page $page, ?Page $parent): Collection
    {
        return Page::query()
            ->where('project_id', $page->project_id)
            ->where('parent_id', $parent?->id)
            ->whereKeyNot($page->id)
            ->orderBy('position')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, Page>  $siblings
     * @return array{before: int|null, after: int|null}
     */
    private function slotFor(Collection $siblings, Page $page, ?Page $after): array
    {
        $index = 0;

        if ($after !== null) {
            $anchor = $siblings->search(fn (Page $candidate): bool => $candidate->is($after));

            if ($anchor === false) {
                throw PageException::anchorIsNotASibling();
            }

            $index = $anchor + 1;
        }

        return [
            'before' => $index === 0 ? null : $siblings->get($index - 1)?->position,
            'after' => $siblings->get($index)?->position,
        ];
    }

    /**
     * Rewrite one level's positions to an even spread. Every row is parked in negative space
     * first, so no write can land on a row that has not moved yet — the sibling constraint
     * would otherwise make the rewrite order load-bearing.
     *
     * @return Collection<int, Page>
     */
    private function normalise(Page $page, ?Page $parent): Collection
    {
        // The page being moved is parked first. It is excluded from its siblings because it is
        // being placed rather than placed against — but it may still be sitting in this level,
        // on a position the respread is about to hand to somebody else.
        $page->forceFill(['position' => SparsePosition::parking(0)])->save();

        $siblings = $this->lockedSiblings($page, $parent);

        foreach ($siblings as $index => $sibling) {
            $sibling->forceFill(['position' => SparsePosition::parking($index + 1)])->save();
        }

        $spread = SparsePosition::spread($siblings->count());

        foreach ($siblings as $index => $sibling) {
            $sibling->forceFill(['position' => $spread[$index]])->save();
        }

        return $siblings;
    }
}
