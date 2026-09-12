<?php

declare(strict_types=1);

use App\Domain\File\Actions\DetachFile;
use App\Domain\File\Events\FileDetached;
use App\Domain\File\Exceptions\FileException;
use App\Domain\File\Models\Attachment;
use App\Domain\File\Models\File;
use App\Domain\File\Policies\AttachmentPolicy;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake(config('filesystems.attachments'));
});

function detachFile(Attachment $attachment, User $actor): void
{
    app(DetachFile::class)->handle($attachment, $actor);
}

it('is the policy the framework finds for an attachment', function (): void {
    expect(Gate::getPolicyFor(Attachment::class))->toBeInstanceOf(AttachmentPolicy::class);
});

it('takes the file off the task', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $uploader = memberOf($workspace);
    $file = File::factory()->in($workspace)->by($uploader)->create();
    $attachment = Attachment::factory()->attaching($file, $task)->create();

    detachFile($attachment, $uploader);

    expect($task->attachments()->count())->toBe(0);
});

it('removes the file once nothing points at it any more', function (): void {
    $workspace = Workspace::factory()->create();
    $uploader = memberOf($workspace);
    $file = File::factory()->in($workspace)->by($uploader)->create();
    $first = Attachment::factory()->attaching($file, Task::factory()->in($workspace)->create())->create();
    $second = Attachment::factory()->attaching($file, Task::factory()->in($workspace)->create())->create();

    detachFile($first, $uploader);

    expect(File::query()->whereKey($file->id)->exists())->toBeTrue();

    detachFile($second, $uploader);

    expect(File::query()->whereKey($file->id)->exists())->toBeFalse()
        ->and(DB::table('files')->where('id', $file->id)->whereNotNull('deleted_at')->exists())->toBeTrue();
});

it('leaves the object on the disk alone', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $uploader = memberOf($workspace);
    $file = File::factory()->in($workspace)->by($uploader)->create();
    Storage::disk($file->disk)->put($file->path, 'the contents');
    $attachment = Attachment::factory()->attaching($file, $task)->create();

    detachFile($attachment, $uploader);

    // Orphaned objects are removed later by the sweep job, never inside the request.
    Storage::disk($file->disk)->assertExists($file->path);
});

it('says whether that was the last thing pointing at the file', function (): void {
    $workspace = Workspace::factory()->create();
    $uploader = memberOf($workspace);
    $file = File::factory()->in($workspace)->by($uploader)->create();
    $first = Attachment::factory()->attaching($file, Task::factory()->in($workspace)->create())->create();
    $second = Attachment::factory()->attaching($file, Task::factory()->in($workspace)->create())->create();

    Event::fake();
    detachFile($first, $uploader);
    Event::assertDispatched(FileDetached::class, fn (FileDetached $event): bool => $event->fileRemoved === false);

    Event::fake();
    detachFile($second, $uploader);
    Event::assertDispatched(FileDetached::class, fn (FileDetached $event): bool => $event->fileRemoved === true
        && $event->fileId === $file->id
        && $event->workspaceId === $workspace->id
        && $event->actorId === $uploader->id);
});

it('lets a moderator remove somebody else s file', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $file = File::factory()->in($workspace)->create();
    $attachment = Attachment::factory()->attaching($file, $task)->create();

    // file.delete is held by every full member. See ADR-0010.
    detachFile($attachment, $admin);

    expect($task->attachments()->count())->toBe(0);
});

it('refuses somebody who holds neither the upload nor the capability', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $file = File::factory()->in($workspace)->create();
    $attachment = Attachment::factory()->attaching($file, $task)->create();

    expect(fn () => detachFile($attachment, $guest))
        ->toThrow(FileException::class, 'You do not have permission to remove this attachment.');

    expect($task->attachments()->count())->toBe(1);
});

it('refuses an uploader whose membership is no longer live', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $revoked = memberOf($workspace, WorkspaceRole::Member, WorkspaceMembershipStatus::Revoked);
    $file = File::factory()->in($workspace)->by($revoked)->create();
    $attachment = Attachment::factory()->attaching($file, $task)->create();

    expect(fn () => detachFile($attachment, $revoked))->toThrow(FileException::class);
});

it('refuses somebody from another workspace entirely', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $file = File::factory()->in($workspace)->create();
    $attachment = Attachment::factory()->attaching($file, $task)->create();
    $stranger = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    expect(fn () => detachFile($attachment, $stranger))->toThrow(FileException::class);
});

it('removes an attachment over HTTP', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $file = File::factory()->in($workspace)->by($actor)->create();
    $attachment = Attachment::factory()->attaching($file, $task)->create();

    $this->actingAs($actor)
        ->delete(route('attachments.destroy', $attachment))
        ->assertRedirect();

    expect($task->attachments()->count())->toBe(0);
});

it('refuses a removal over HTTP to somebody who may not', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $task = Task::factory()->in($workspace)->create();
    $file = File::factory()->in($workspace)->create();
    $attachment = Attachment::factory()->attaching($file, $task)->create();

    $this->actingAs($guest)
        ->delete(route('attachments.destroy', $attachment))
        ->assertForbidden();

    expect($task->attachments()->count())->toBe(1);
});
