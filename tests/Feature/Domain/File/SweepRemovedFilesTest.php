<?php

declare(strict_types=1);

use App\Domain\File\Models\Attachment;
use App\Domain\File\Models\File;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake(config('filesystems.attachments'));
});

/**
 * A file with its object actually on the fake disk, removed the given number of days ago.
 */
function removedFile(Workspace $workspace, int $daysAgo): File
{
    $file = File::factory()->in($workspace)->create();

    Storage::disk($file->disk)->put($file->path, 'the contents');

    $file->delete();
    $file->forceFill(['deleted_at' => now()->subDays($daysAgo)])->saveQuietly();

    return $file;
}

it('deletes the objects of files removed longer ago than the window', function (): void {
    $workspace = Workspace::factory()->create();
    $old = removedFile($workspace, 40);

    $this->artisan('files:sweep')->assertSuccessful();

    /*
     * The only place in this application where bytes are destroyed: on a schedule, after a
     * window, where a mistake is noticed before it is permanent (TASK-120-007).
     */
    Storage::disk($old->disk)->assertMissing($old->path);
    expect(DB::table('files')->where('id', $old->id)->exists())->toBeFalse();
});

it('leaves a file that was removed recently', function (): void {
    $workspace = Workspace::factory()->create();
    $recent = removedFile($workspace, 2);

    $this->artisan('files:sweep')->assertSuccessful();

    // The window is what makes the irreversible step recoverable at all.
    Storage::disk($recent->disk)->assertExists($recent->path);
    expect(DB::table('files')->where('id', $recent->id)->exists())->toBeTrue();
});

it('never touches a file something still points at', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $file = removedFile($workspace, 90);
    Attachment::factory()->attaching($file, $task)->create();

    /*
     * A soft-deleted file with a live attachment should not exist — `DetachFile` removes the
     * attachment first. This is the belt: the sweep is the one operation that cannot be undone,
     * so it refuses to act on anything still referenced rather than trusting the invariant.
     */
    $this->artisan('files:sweep')->assertSuccessful();

    Storage::disk($file->disk)->assertExists($file->path);
    expect(DB::table('files')->where('id', $file->id)->exists())->toBeTrue();
});

it('leaves files nobody removed alone', function (): void {
    $workspace = Workspace::factory()->create();
    $file = File::factory()->in($workspace)->create();
    Storage::disk($file->disk)->put($file->path, 'the contents');

    $this->artisan('files:sweep')->assertSuccessful();

    Storage::disk($file->disk)->assertExists($file->path);
    expect(File::query()->whereKey($file->id)->exists())->toBeTrue();
});

it('reads the disk from the row rather than from configuration', function (): void {
    $workspace = Workspace::factory()->create();
    Storage::fake('legacy');

    $file = File::factory()->in($workspace)->create(['disk' => 'legacy']);
    Storage::disk('legacy')->put($file->path, 'the contents');
    $file->delete();
    $file->forceFill(['deleted_at' => now()->subDays(60)])->saveQuietly();

    // A file written before `FILESYSTEM_ATTACHMENTS_DISK` changed is still findable, which is
    // why the row records its disk at all (ADR-0007).
    $this->artisan('files:sweep')->assertSuccessful();

    Storage::disk('legacy')->assertMissing($file->path);
});

it('says how many it swept', function (): void {
    $workspace = Workspace::factory()->create();
    removedFile($workspace, 40);
    removedFile($workspace, 40);

    $this->artisan('files:sweep')->expectsOutputToContain('Swept 2 files.')->assertSuccessful();
});
