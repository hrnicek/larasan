<?php

declare(strict_types=1);

use App\Domain\File\Actions\MakeThumbnail;
use App\Domain\File\Events\FileAttached;
use App\Domain\File\Listeners\GenerateThumbnail;
use App\Domain\File\Models\Attachment;
use App\Domain\File\Models\File;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Queries\TaskDetailQuery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake(config('filesystems.attachments'));
});

/**
 * A real picture, because a thumbnailer given fake bytes proves only that it refuses them. Drawn
 * rather than fixtured so the test carries its own input and the repository carries no binaries.
 */
function pictureBytes(int $width = 900, int $height = 600): string
{
    $image = imagecreatetruecolor(max(1, $width), max(1, $height));
    $blue = (int) imagecolorallocate($image, 20, 120, 200);

    imagefilledrectangle($image, 0, 0, $width, $height, $blue);

    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();

    imagedestroy($image);

    return $bytes;
}

/**
 * The derivative a file ended up with, or a failure that says so — `thumbnail()` is nullable and
 * every assertion below would otherwise carry its own guard.
 *
 * @return array{path: string, width: int, height: int}
 */
function thumbnailOf(File $file): array
{
    return $file->refresh()->thumbnail() ?? throw new RuntimeException('No thumbnail was derived.');
}

function storedPicture(Task $task, int $width = 900, int $height = 600): File
{
    $file = File::factory()->in($task->workspace)->image()->create();

    Storage::disk($file->disk)->put($file->path, pictureBytes($width, $height));
    Attachment::factory()->attaching($file, $task)->create();

    return $file;
}

it('derives a thumbnail whose longer edge is the size it says', function (): void {
    [$workspace] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $file = storedPicture($task);

    expect(app(MakeThumbnail::class)->handle($file))->toBeTrue();

    $thumbnail = thumbnailOf($file);

    // 900x600 scaled to a 480 box keeps its ratio rather than being squared off.
    expect($thumbnail['width'])->toBe(480)
        ->and($thumbnail['height'])->toBe(320);

    Storage::disk($file->disk)->assertExists($thumbnail['path']);

    // The shape of the original is recorded too: a tile reserves its space before the bytes land.
    expect($file->refresh()->imageDimensions())->toBe(['width' => 900, 'height' => 600]);
});

it('leaves a picture smaller than the box alone', function (): void {
    [$workspace] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $file = storedPicture($task, 120, 90);

    app(MakeThumbnail::class)->handle($file);

    // Enlarging a small picture makes a larger file and no more detail.
    expect(thumbnailOf($file))->toMatchArray(['width' => 120, 'height' => 90]);
});

it('derives nothing for a file that is not an image', function (): void {
    [$workspace] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $file = File::factory()->in($workspace)->create();
    Storage::disk($file->disk)->put($file->path, 'a plan');
    Attachment::factory()->attaching($file, $task)->create();

    expect(app(MakeThumbnail::class)->handle($file))->toBeFalse()
        ->and($file->refresh()->thumbnail())->toBeNull();
});

it('derives nothing from bytes it cannot read as a picture', function (): void {
    [$workspace] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $file = File::factory()->in($workspace)->image()->create();
    Storage::disk($file->disk)->put($file->path, 'not a picture at all');

    // The upload already succeeded. A derivative that cannot be made is a heavier page, not a
    // failure the person who uploaded it hears about.
    expect(app(MakeThumbnail::class)->handle($file))->toBeFalse();
});

it('does not derive the same thumbnail twice', function (): void {
    [$workspace] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $file = storedPicture($task);

    app(MakeThumbnail::class)->handle($file);

    // One file hangs from two tasks, so the listener runs again for a derivative that exists.
    expect(app(MakeThumbnail::class)->handle($file->refresh()))->toBeFalse();
});

it('makes the thumbnail when a file is attached', function (): void {
    [$workspace] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $file = storedPicture($task);
    $attachment = $task->attachments()->sole();

    app(GenerateThumbnail::class)->handle(new FileAttached(
        $file->id,
        $attachment->id,
        $workspace->id,
        'task',
        $task->id,
        (int) $file->uploaded_by,
    ));

    expect($file->refresh()->thumbnail())->not->toBeNull();
});

it('serves the derivative when the thumbnail is asked for', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $file = storedPicture($task);
    app(MakeThumbnail::class)->handle($file);
    $attachment = $task->attachments()->sole();

    $response = $this->actingAs($actor)->get(route('attachments.preview', ['attachment' => $attachment, 'size' => 'thumb']));

    $response->assertOk();

    expect($response->headers->get('content-type'))->toBe('image/webp')
        // Two sizes at one address are two responses, so a cached thumbnail must not satisfy a
        // request for the full picture.
        ->and($response->headers->get('etag'))->toBe('"'.$file->checksum.'-thumb"');
});

it('falls back to the original while the queue is behind', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $file = storedPicture($task);
    $attachment = $task->attachments()->sole();

    $response = $this->actingAs($actor)->get(route('attachments.preview', ['attachment' => $attachment, 'size' => 'thumb']));

    // A heavier page, never a broken one.
    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('image/png');
});

it('takes the derivative with the object it was derived from', function (): void {
    [$workspace] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $file = storedPicture($task);
    app(MakeThumbnail::class)->handle($file);
    $thumbnail = thumbnailOf($file);

    $task->attachments()->sole()->delete();
    $file->refresh()->delete();
    File::withTrashed()->whereKey($file->id)->update(['deleted_at' => now()->subDays(90)]);

    expect(Artisan::call('files:sweep'))->toBe(0);

    Storage::disk($file->disk)->assertMissing($thumbnail['path']);
    Storage::disk($file->disk)->assertMissing($file->path);
});

it('backfills what was attached before thumbnails existed', function (): void {
    [$workspace] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $file = storedPicture($task);

    expect(Artisan::call('files:thumbnails'))->toBe(0);

    expect($file->refresh()->thumbnail())->not->toBeNull();
});

it('tells the screen what shape the picture is', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $file = storedPicture($task);
    app(MakeThumbnail::class)->handle($file);

    $payload = app(TaskDetailQuery::class)($task->fresh(), $actor);

    expect($payload['attachments'][0]['image'])->toBe(['width' => 900, 'height' => 600]);
});
