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

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('attachments.sweep_after_days'));

        $swept = 0;

        File::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->whereDoesntHave('attachments')
            ->chunkById(100, function ($files) use (&$swept): void {
                foreach ($files as $file) {
                    // The disk is read per row so files stored before the attachments disk
                    // changed are still found. See ADR-0007.
                    $disk = Storage::disk($file->disk);
                    $thumbnail = $file->thumbnail();

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
