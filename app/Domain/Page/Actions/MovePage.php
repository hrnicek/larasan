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
     * Rows are parked in negative space first so no write collides with the sibling unique constraint.
     *
     * @return Collection<int, Page>
     */
    private function normalise(Page $page, ?Page $parent): Collection
    {
        // The moved page is excluded from the siblings but may still hold a position in this level.
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
