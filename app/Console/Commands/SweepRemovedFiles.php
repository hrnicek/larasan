<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\File\Models\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SweepRemovedFiles extends Command
{
    protected $signature = 'files:sweep';

    protected $description = 'Delete the objects of files removed longer ago than the retention window';

    /**
     * The other half of TASK-120-007's decision.
     *
     * Removing an attachment soft-deletes the file and deliberately leaves the object where it
     * is: deleting bytes inside a request is the one thing in this application that cannot be
     * undone. That makes this command the only place bytes are destroyed — on a schedule, after
     * a window, where a mistake is noticed before it is permanent, and never while something
     * still points at the file.
     *
     * The disk comes from each row rather than from configuration, so files written before
     * `FILESYSTEM_ATTACHMENTS_DISK` changed are still found (ADR-0007).
     */
    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('attachments.sweep_after_days'));

        $swept = 0;

        File::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->whereDoesntHave('attachments')
            ->chunkById(100, function ($files) use (&$swept): void {
                foreach ($files as $file) {
                    $disk = Storage::disk($file->disk);
                    $thumbnail = $file->thumbnail();

                    // The derivative goes with what it was derived from. Left behind it would be
                    // an object nothing points at, which is precisely what this command exists
                    // to stop accumulating.
                    if ($thumbnail !== null) {
                        $disk->delete($thumbnail['path']);
                    }

                    $disk->delete($file->path);

                    $file->forceDelete();

                    $swept++;
                }
            });

        $this->components->info("Swept {$swept} ".str('file')->plural($swept).'.');

        return self::SUCCESS;
    }
}
