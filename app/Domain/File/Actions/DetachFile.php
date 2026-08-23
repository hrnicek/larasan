<?php

declare(strict_types=1);

namespace App\Domain\File\Actions;

use App\Domain\File\Events\FileDetached;
use App\Domain\File\Exceptions\FileException;
use App\Domain\File\Models\Attachment;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Take a file off something.
 *
 * Three things happen in order, and the order is the design:
 *
 * 1. the attachment row goes, because that is what "remove this from here" means;
 * 2. the file is **soft-deleted once nothing points at it any more** — not while something
 *    still does, because one document can hang from two tasks and removing it from one is not
 *    removing it;
 * 3. the object on the disk is left alone. Deleting bytes inside a request is the one part of
 *    this that cannot be undone, and the row being unreachable is what the reader experiences
 *    either way. Sweeping the orphaned objects is TASK-120-012, deliberately a separate,
 *    reversible-until-it-runs job.
 */
final readonly class DetachFile
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Attachment $attachment, User $actor): void
    {
        if ($actor->cannot('delete', $attachment)) {
            throw FileException::cannotRemoveAttachment();
        }

        $file = $attachment->file;
        $subjectType = $attachment->attachable_type;
        $subjectId = $attachment->attachable_id;

        $removed = DB::transaction(function () use ($attachment, $file): bool {
            $attachment->delete();

            if ($file->attachments()->exists()) {
                return false;
            }

            $file->delete();

            return true;
        });

        $this->events->dispatch(new FileDetached(
            $file->id,
            $file->workspace_id,
            $subjectType,
            $subjectId,
            $actor->id,
            $removed,
        ));
    }
}
