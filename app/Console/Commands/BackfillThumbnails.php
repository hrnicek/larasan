<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\File\Actions\MakeThumbnail;
use App\Domain\File\Models\File;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class BackfillThumbnails extends Command
{
    protected $signature = 'files:thumbnails {--force : Re-derive files that already have one}';

    protected $description = 'Derive the missing thumbnails of images attached before they existed';

    public function handle(MakeThumbnail $makeThumbnail): int
    {
        $made = 0;
        $skipped = 0;
        $force = (bool) $this->option('force');

        File::query()
            ->where('mime_type', 'like', 'image/%')
            ->orderBy('id')
            ->chunkById(100, function (Collection $files) use ($makeThumbnail, $force, &$made, &$skipped): void {
                foreach ($files as $file) {
                    $makeThumbnail->handle($file, $force) ? $made++ : $skipped++;
                }
            });

        $this->components->info("Derived {$made} ".str('thumbnail')->plural($made).", skipped {$skipped}.");

        return self::SUCCESS;
    }
}
