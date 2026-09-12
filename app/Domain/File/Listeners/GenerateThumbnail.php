<?php

declare(strict_types=1);

namespace App\Domain\File\Listeners;

use App\Domain\File\Actions\MakeThumbnail;
use App\Domain\File\Events\FileAttached;
use App\Domain\File\Models\File;
use Illuminate\Contracts\Queue\ShouldQueue;

final readonly class GenerateThumbnail implements ShouldQueue
{
    public function __construct(private MakeThumbnail $makeThumbnail) {}

    /** A separate queue so bursts of resizes cannot delay broadcasts or notifications. See ADR-0008. */
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
