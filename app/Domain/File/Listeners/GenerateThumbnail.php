<?php

declare(strict_types=1);

namespace App\Domain\File\Listeners;

use App\Domain\File\Actions\MakeThumbnail;
use App\Domain\File\Events\FileAttached;
use App\Domain\File\Models\File;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Make the small copy on the way in.
 *
 * Queued, because resizing a photograph is the slowest thing this application does and the person
 * who uploaded it is waiting on a redirect. The screens are written to work without it: a preview
 * falls back to the original while this has not run, so queue lag is a heavier page rather than a
 * broken one.
 *
 * It does nothing twice. One file can be attached to two tasks — that is the whole point of the
 * split between `files` and `attachments` — and this fires per attachment, so the derivative
 * already existing is the ordinary case rather than an error.
 */
final readonly class GenerateThumbnail implements ShouldQueue
{
    public function __construct(private MakeThumbnail $makeThumbnail) {}

    /**
     * Its own queue, below broadcasts and notifications (ADR-0008). A burst of photographs must
     * not put a board update or somebody's inbox behind twenty resizes.
     */
    public function viaQueue(): string
    {
        return 'media';
    }

    public function handle(FileAttached $event): void
    {
        $file = File::query()->find($event->fileId);

        if ($file === null) {
            return;
        }

        $this->makeThumbnail->handle($file);
    }
}
