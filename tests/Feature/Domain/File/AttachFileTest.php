<?php

declare(strict_types=1);

use App\Domain\File\Actions\AttachFile;
use App\Domain\File\Actions\AttachFiles;
use App\Domain\File\Events\FileAttached;
use App\Domain\File\Exceptions\FileException;
use App\Domain\File\Models\Attachment;
use App\Domain\File\Models\File;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Storage::fake(config('filesystems.attachments'));
});

function attachTo(Task $task, User $actor, ?UploadedFile $upload = null): Attachment
{
    return app(AttachFile::class)->handle($task, $actor, $upload ?? UploadedFile::fake()->create('quarterly plan.pdf', 12, 'application/pdf'));
}

it('stores the object and records where it went', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $attachment = attachTo($task, $actor);
    $file = $attachment->file;

    expect($file->workspace_id)->toBe($workspace->id)
        ->and($file->uploaded_by)->toBe($actor->id)
        ->and($file->original_name)->toBe('quarterly plan.pdf')
        ->and($file->disk)->toBe(config('filesystems.attachments'))
        ->and($task->attachments()->count())->toBe(1);

    Storage::disk(config('filesystems.attachments'))->assertExists($file->path);
});

it('generates the path and never derives it from the name', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $file = attachTo($task, $actor, UploadedFile::fake()->create('../../etc/passwd.pdf', 4, 'application/pdf'))->file;

    expect($file->path)->toStartWith("workspaces/{$workspace->id}/task/")
        ->and($file->path)->not->toContain('..')
        ->and($file->path)->not->toContain('passwd')
        // UploadedFile already reduces the client name to its basename.
        ->and($file->original_name)->toBe('passwd.pdf');
});

it('takes the workspace from the subject rather than from the request', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $elsewhere = Workspace::factory()->create();
    memberOf($elsewhere, WorkspaceRole::Member, user: $actor);
    $actor->forceFill(['current_workspace_id' => $elsewhere->id])->save();

    expect(attachTo($task, $actor)->file->workspace_id)->toBe($workspace->id);
});

it('records what arrived rather than what the disk read back', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $upload = UploadedFile::fake()->createWithContent('notes.txt', 'the contents');

    $file = attachTo($task, $actor, $upload)->file;

    expect($file->checksum)->toBe(hash('sha256', 'the contents'))
        ->and($file->size)->toBe(strlen('the contents'));
});

it('announces the attachment it made', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    Event::fake();
    $attachment = attachTo($task, $actor);

    Event::assertDispatched(FileAttached::class, fn (FileAttached $event): bool => $event->attachmentId === $attachment->id
        && $event->workspaceId === $workspace->id
        && $event->subjectType === 'task'
        && $event->subjectId === $task->id
        && $event->uploadedById === $actor->id);
});

it('refuses somebody who cannot reach the subject, and stores nothing', function (): void {
    $workspace = Workspace::factory()->create();
    $outsider = memberOf($workspace, WorkspaceRole::Member);
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();

    expect(fn (): Attachment => attachTo($task, $outsider))
        ->toThrow(FileException::class, 'You cannot attach a file to something you cannot reach.');

    expect(File::query()->count())->toBe(0);
    Storage::disk(config('filesystems.attachments'))->assertDirectoryEmpty('workspaces');
});

it('refuses a guest, who may say things but may not upload them', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Editor)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    expect(fn (): Attachment => attachTo($task, $guest))
        ->toThrow(FileException::class, 'You do not have permission to upload files in this workspace.');
});

it('refuses somebody whose membership is no longer live', function (): void {
    $workspace = Workspace::factory()->create();
    $revoked = memberOf($workspace, WorkspaceRole::Member, WorkspaceMembershipStatus::Revoked);
    $task = Task::factory()->in($workspace)->create();

    expect(fn (): Attachment => attachTo($task, $revoked))->toThrow(FileException::class);

    expect(File::query()->count())->toBe(0);
});

it('refuses somebody from another workspace entirely', function (): void {
    [$workspace] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $stranger = memberOf(Workspace::factory()->create());

    expect(fn (): Attachment => attachTo($task, $stranger))->toThrow(FileException::class);
});

it('leaves no row when the object cannot be stored', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    Storage::shouldReceive('disk')->andReturnSelf();
    Storage::shouldReceive('putFileAs')->andReturnFalse();

    expect(fn (): Attachment => attachTo($task, $actor))
        ->toThrow(FileException::class, 'That file could not be stored. Nothing was attached.');

    expect(File::query()->count())->toBe(0)
        ->and(Attachment::query()->count())->toBe(0);
});

it('writes the morph alias rather than a class name', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    expect(attachTo($task, $actor)->attachable_type)->toBe('task');
});

it('drops an extension too long to be one instead of failing the upload', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $file = attachTo($task, $actor, UploadedFile::fake()->create('notes.'.str_repeat('x', 40), 4, 'text/plain'))->file;

    expect($file->extension)->toBe('')
        ->and(pathinfo($file->path, PATHINFO_EXTENSION))->toBe('');

    Storage::disk(config('filesystems.attachments'))->assertExists($file->path);
});

it('removes the stored object when the rows cannot be written', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    Attachment::creating(function (Attachment $attachment): void {
        $attachment->file_id = (string) Str::uuid7();
    });

    expect(fn (): Attachment => attachTo($task, $actor))->toThrow(QueryException::class);

    expect(File::query()->count())->toBe(0)
        ->and(Storage::disk(config('filesystems.attachments'))->allFiles())->toBe([]);
});

it('recovers when an upload took the last slot between the read and the write', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $squatter = File::factory()->in($workspace)->create();
    $injected = false;

    Attachment::creating(function (Attachment $attachment) use (&$injected, $squatter): void {
        if ($injected) {
            return;
        }

        $injected = true;

        DB::table('attachments')->insert([
            'id' => (string) Str::uuid7(),
            'file_id' => $squatter->id,
            'attachable_type' => $attachment->attachable_type,
            'attachable_id' => $attachment->attachable_id,
            'position' => $attachment->position,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $attachment = attachTo($task, $actor);

    expect($injected)->toBeTrue()
        ->and($task->attachments()->pluck('id')->all())->toBe([$attachment->id]);

    Storage::disk(config('filesystems.attachments'))->assertExists($attachment->file->path);
});

it('refuses somebody who may only view every project the task is in, and stores nothing', function (): void {
    [$workspace, $project, $viewer] = placeableProject(ProjectAccessLevel::Viewer);
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    expect(fn (): Attachment => attachTo($task, $viewer))
        ->toThrow(FileException::class, 'You can open this, but you do not have permission to attach files to it.');

    expect(File::query()->count())->toBe(0)
        ->and(Storage::disk(config('filesystems.attachments'))->allFiles())->toBe([]);
});

it('attaches a batch whole or not at all', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $uploads = fn (): array => [
        UploadedFile::fake()->create('first.pdf', 4, 'application/pdf'),
        UploadedFile::fake()->create('second.pdf', 4, 'application/pdf'),
    ];

    expect(app(AttachFiles::class)->handle($task, $actor, $uploads()))->toHaveCount(2);

    Attachment::creating(function (Attachment $attachment) use ($task): void {
        if ($task->attachments()->count() === 3) {
            $attachment->file_id = (string) Str::uuid7();
        }
    });

    expect(fn (): array => app(AttachFiles::class)->handle($task, $actor, $uploads()))->toThrow(QueryException::class);

    expect($task->attachments()->count())->toBe(2)
        ->and(Storage::disk(config('filesystems.attachments'))->allFiles())->toHaveCount(2);
});

it('shortens a name too long to store, keeping every character whole and the extension', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $file = attachTo($task, $actor, UploadedFile::fake()->create(str_repeat('ž', 300).'.PDF', 4, 'application/pdf'))->file;

    expect(mb_strlen($file->original_name))->toBe(AttachFile::MAX_NAME_LENGTH)
        ->and(mb_check_encoding($file->original_name, 'UTF-8'))->toBeTrue()
        ->and($file->original_name)->toBe(str_repeat('ž', AttachFile::MAX_NAME_LENGTH - 4).'.PDF');
});

it('shortens a long name whose extension is not one it keeps', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $file = attachTo($task, $actor, UploadedFile::fake()->create('notes.'.str_repeat('ž', 300), 4, 'text/plain'))->file;

    expect($file->original_name)->toBe(mb_substr('notes.'.str_repeat('ž', 300), 0, AttachFile::MAX_NAME_LENGTH))
        ->and($file->extension)->toBe('');
});
