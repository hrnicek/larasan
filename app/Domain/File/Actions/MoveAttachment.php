<?php

declare(strict_types=1);

namespace App\Domain\File\Actions;

use App\Domain\File\Exceptions\FileException;
use App\Domain\File\Models\Attachment;
use App\Domain\Shared\Ordering\PositionsNeedNormalisation;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Put this file after that one.
 *
 * An anchor, never a position (ADR-0009), for the reason a section move takes one: a screen that
 * computed positions from what it last saw would write two files into the same slot as soon as
 * somebody else had moved one. Reordering is `task.update` through the subject's own policy —
 * the order of a task's files is part of the task, and a reader who may open it does not get to
 * rearrange it.
 */
final readonly class MoveAttachment
{
    /**
     * @param  Attachment|null  $after  the attachment this one goes behind, or null for the front
     */
    public function handle(Attachment $attachment, User $actor, ?Attachment $after): Attachment
    {
        if ($actor->cannot('move', $attachment)) {
            throw FileException::cannotReorderAttachments();
        }

        if ($after !== null && ! $this->sameSubject($attachment, $after)) {
            throw FileException::attachmentBelongsToAnotherSubject();
        }

        if ($after !== null && $after->is($attachment)) {
            throw FileException::cannotFollowItself();
        }

        try {
            $this->place($attachment, $after);
        } catch (UniqueConstraintViolationException) {
            // Two moves computed the same midpoint. The constraint made that an error rather
            // than two files in one slot; this one reads the order again and places itself
            // relative to the winner.
            $this->place($attachment->fresh() ?? $attachment, $after?->fresh());
        }

        return $attachment->refresh();
    }

    private function sameSubject(Attachment $attachment, Attachment $other): bool
    {
        return $other->attachable_type === $attachment->attachable_type
            && $other->attachable_id === $attachment->attachable_id;
    }

    private function place(Attachment $attachment, ?Attachment $after): void
    {
        DB::transaction(function () use ($attachment, $after): void {
            $ordered = $this->lockedOrder($attachment);

            $target = $this->slotFor($ordered, $attachment, $after);

            if ($target === null) {
                return;
            }

            try {
                $position = SparsePosition::between($target['before'], $target['after']);
            } catch (PositionsNeedNormalisation) {
                // The neighbours have closed up, so there is no midpoint left to take. The
                // subject's whole list is respread inside this transaction and the slot is
                // recomputed — normalisation is the exception, not the steady state.
                $ordered = $this->normalise($attachment);
                $target = $this->slotFor($ordered, $attachment, $after);

                if ($target === null) {
                    return;
                }

                $position = SparsePosition::between($target['before'], $target['after']);
            }

            $attachment->forceFill(['position' => $position])->save();
        });
    }

    /**
     * The neighbours the attachment lands between, or null when it is already there.
     *
     * @param  Collection<int, Attachment>  $ordered
     * @return array{before: int|null, after: int|null}|null
     */
    private function slotFor(Collection $ordered, Attachment $attachment, ?Attachment $after): ?array
    {
        $others = $ordered->reject(fn (Attachment $candidate): bool => $candidate->is($attachment))->values();

        $index = 0;

        if ($after !== null) {
            $anchor = $others->search(fn (Attachment $candidate): bool => $candidate->is($after));

            // The anchor is not in this subject's order at all — a stale screen, or a file that
            // has since been removed. Placing "after" it would otherwise mean the front.
            if ($anchor === false) {
                throw FileException::attachmentBelongsToAnotherSubject();
            }

            $index = $anchor + 1;
        }

        $before = $index === 0 ? null : $others->get($index - 1)?->position;
        $next = $others->get($index)?->position;

        $current = $ordered->search(fn (Attachment $candidate): bool => $candidate->is($attachment));

        // Already in that slot: the same neighbours, in the same order, so nothing to write.
        if ($current !== false && $this->alreadyBetween($ordered, $current, $before, $next)) {
            return null;
        }

        return ['before' => $before, 'after' => $next];
    }

    /**
     * @param  Collection<int, Attachment>  $ordered
     */
    private function alreadyBetween(Collection $ordered, int $current, ?int $before, ?int $next): bool
    {
        $previousPosition = $current === 0 ? null : $ordered->get($current - 1)?->position;
        $nextPosition = $ordered->get($current + 1)?->position;

        return $previousPosition === $before && $nextPosition === $next;
    }

    /**
     * Everything hanging from the same subject, locked and in order, so a second move waits
     * instead of reading the same neighbours.
     *
     * @return Collection<int, Attachment>
     */
    private function lockedOrder(Attachment $attachment): Collection
    {
        return Attachment::query()
            ->where('attachable_type', $attachment->attachable_type)
            ->where('attachable_id', $attachment->attachable_id)
            ->orderBy('position')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Rewrite the subject's positions to an even spread. Every row is parked in negative space
     * first, so no write lands on a row that has not moved yet — the unique constraint would
     * otherwise make the rewrite order load-bearing.
     *
     * @return Collection<int, Attachment>
     */
    private function normalise(Attachment $attachment): Collection
    {
        $ordered = $this->lockedOrder($attachment);

        foreach ($ordered as $index => $row) {
            $row->forceFill(['position' => SparsePosition::parking($index)])->save();
        }

        $spread = SparsePosition::spread($ordered->count());

        foreach ($ordered as $index => $row) {
            $row->forceFill(['position' => $spread[$index]])->save();
        }

        return $ordered;
    }
}
