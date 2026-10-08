<?php

declare(strict_types=1);

namespace App\Domain\File\Actions;

use App\Domain\File\Events\FileDetached;
use App\Domain\File\Exceptions\FileException;
use App\Domain\File\Models\Attachment;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

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

            // The stored object is left in place; files:sweep removes it after the retention window.
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
