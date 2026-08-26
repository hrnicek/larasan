<?php

declare(strict_types=1);

use App\Domain\File\Models\Attachment;
use App\Domain\File\Models\File;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Queries\TaskDetailQuery;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake(config('filesystems.attachments'));
});

/**
 * An image whose object exists on the fake disk, so a preview reads bytes rather than a row.
 */
function storedImage(Task $task, string $mimeType = 'image/png', string $extension = 'png'): Attachment
{
    $file = File::factory()->in($task->workspace)->image($mimeType, $extension)->create();

    Storage::disk($file->disk)->put($file->path, 'the bytes');

    return Attachment::factory()->attaching($file, $task)->create();
}

it('draws an image rather than filing it', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $attachment = storedImage($task);

    $response = $this->actingAs($actor)->get(route('attachments.preview', $attachment));

    $response->assertOk();

    expect($response->headers->get('content-disposition'))->toStartWith('inline')
        ->and($response->headers->get('content-type'))->toBe('image/png')
        // The browser is not allowed to decide this is something else.
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff')
        ->and($response->headers->get('cache-control'))->toContain('immutable')
        ->and($response->headers->get('etag'))->toBe('"'.$attachment->file->checksum.'"');
});

it('refuses to render anything it would be dangerous to render on this origin', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $attachment = storedImage($task, 'image/svg+xml', 'svg');

    /*
     * An SVG is a document with script in it. Served inline it would run against this origin's
     * session, so it is not previewable at all — downloading it is still fine, because a
     * download is not an execution.
     */
    $this->actingAs($actor)->get(route('attachments.preview', $attachment))->assertNotFound();
    $this->actingAs($actor)->get(route('attachments.download', $attachment))->assertOk();
});

it('will not preview a document either', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $file = File::factory()->in($workspace)->create();
    Storage::disk($file->disk)->put($file->path, 'a plan');
    $attachment = Attachment::factory()->attaching($file, $task)->create();

    $this->actingAs($actor)->get(route('attachments.preview', $attachment))->assertNotFound();
});

it('refuses a leaked id from a project the actor was never given', function (): void {
    $workspace = Workspace::factory()->create();
    $outsider = memberOf($workspace, WorkspaceRole::Member);
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();
    $attachment = storedImage($task);

    // Reach, not knowledge of an id (ADR-0007). The preview asks exactly what the download asks.
    $this->actingAs($outsider)->get(route('attachments.preview', $attachment))->assertForbidden();
});

it('hides an image in another workspace behind a 404', function (): void {
    [$workspace] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $attachment = storedImage($task);
    $stranger = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    $this->actingAs($stranger)->get(route('attachments.preview', $attachment))->assertNotFound();
});

it('answers a matching validator without sending the bytes again', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $attachment = storedImage($task);

    $this->actingAs($actor)
        ->get(route('attachments.preview', $attachment), ['If-None-Match' => '"'.$attachment->file->checksum.'"'])
        ->assertStatus(304);
});

it('answers with a 404 when the row outlives the object', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $attachment = storedImage($task);

    Storage::disk($attachment->file->disk)->delete($attachment->file->path);

    $this->actingAs($actor)->get(route('attachments.preview', $attachment))->assertNotFound();
});

it('turns away everybody who is not signed in', function (): void {
    [$workspace] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $attachment = storedImage($task);

    $this->get(route('attachments.preview', $attachment))->assertRedirect(route('login'));
});

it('says what kind of thing each attachment is', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    storedImage($task);
    $document = File::factory()->in($workspace)->create();
    Attachment::factory()->attaching($document, $task)->create();

    $payload = app(TaskDetailQuery::class)($task->fresh(), $actor);

    // The screen draws a thumbnail or a filename from this, and never from the MIME string.
    expect(collect($payload['attachments'])->pluck('kind')->all())->toBe(['image', 'pdf']);
});
