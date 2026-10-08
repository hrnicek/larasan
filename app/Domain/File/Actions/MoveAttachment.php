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
            // A concurrent move took the same midpoint; re-read the order and place again.
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
                $ordered = $this->normalise($attachment);

                // normalise() rewrote this row through another instance, so save() would compare against a stale position.
                $attachment->refresh();

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
     * @param  Collection<int, Attachment>  $ordered
     * @return array{before: int|null, after: int|null}|null
     */
    private function slotFor(Collection $ordered, Attachment $attachment, ?Attachment $after): ?array
    {
        $others = $ordered->reject(fn (Attachment $candidate): bool => $candidate->is($attachment))->values();

        $index = 0;

        if ($after !== null) {
            $anchor = $others->search(fn (Attachment $candidate): bool => $candidate->is($after));

            // A stale or removed anchor must not silently fall back to the front.
            if ($anchor === false) {
                throw FileException::attachmentBelongsToAnotherSubject();
            }

            $index = $anchor + 1;
        }

        $before = $index === 0 ? null : $others->get($index - 1)?->position;
        $next = $others->get($index)?->position;

        $current = $ordered->search(fn (Attachment $candidate): bool => $candidate->is($attachment));

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
     * Rows are parked first so the unique position constraint is never hit mid-rewrite.
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
