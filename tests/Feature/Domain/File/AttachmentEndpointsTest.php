<?php

declare(strict_types=1);

use App\Domain\File\Models\Attachment;
use App\Domain\File\Models\File;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Storage::fake(config('filesystems.attachments'));
});

function storedAttachment(Task $task, string $contents = 'the contents'): Attachment
{
    $file = File::factory()->in($task->workspace)->create(['original_name' => 'plan.pdf']);

    Storage::disk($file->disk)->put($file->path, $contents);

    return Attachment::factory()->attaching($file, $task)->create();
}

it('attaches a file to a task', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->post(route('tasks.attachments.store', $task), [
            'files' => [UploadedFile::fake()->create('plan.pdf', 12, 'application/pdf')],
        ])
        ->assertRedirect(route('tasks.show', $task));

    $file = $task->attachments()->with('file')->sole()->file;

    expect($file->original_name)->toBe('plan.pdf');
    Storage::disk($file->disk)->assertExists($file->path);
});

it('attaches several files chosen at once, in the order they were chosen', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->post(route('tasks.attachments.store', $task), [
            'files' => [
                UploadedFile::fake()->create('first.pdf', 12, 'application/pdf'),
                UploadedFile::fake()->create('second.pdf', 12, 'application/pdf'),
                UploadedFile::fake()->create('third.pdf', 12, 'application/pdf'),
            ],
        ])
        ->assertRedirect(route('tasks.show', $task));

    $names = $task->attachments()->with('file')->orderBy('position')->get()
        ->map(fn (Attachment $attachment): string => $attachment->file->original_name)
        ->all();

    expect($names)->toBe(['first.pdf', 'second.pdf', 'third.pdf']);
});

it('attaches nothing at all when one file in the batch is refused', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->post(route('tasks.attachments.store', $task), [
            'files' => [
                UploadedFile::fake()->create('fine.pdf', 12, 'application/pdf'),
                UploadedFile::fake()->create('payload.pdf', 10, 'application/x-msdownload'),
            ],
        ])
        ->assertSessionHasErrors('files.1');

    expect($task->attachments()->count())->toBe(0);
    expect(File::query()->count())->toBe(0);
});

it('refuses a batch larger than the configured bound', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    config(['attachments.max_files' => 2]);

    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->post(route('tasks.attachments.store', $task), [
            'files' => [
                UploadedFile::fake()->create('one.pdf', 4, 'application/pdf'),
                UploadedFile::fake()->create('two.pdf', 4, 'application/pdf'),
                UploadedFile::fake()->create('three.pdf', 4, 'application/pdf'),
            ],
        ])
        ->assertSessionHasErrors('files');

    expect($task->attachments()->count())->toBe(0);
});

it('gives the file back under the name people recognise', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $attachment = storedAttachment($task);

    $response = $this->actingAs($actor)->get(route('attachments.download', $attachment));

    $response->assertOk()
        ->assertDownload('plan.pdf');

    expect($response->headers->get('content-disposition'))->not->toContain($attachment->file->path);
});

it('refuses a leaked attachment id from a project the actor was never given', function (): void {
    $workspace = Workspace::factory()->create();
    $outsider = memberOf($workspace, WorkspaceRole::Member);
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();
    $attachment = storedAttachment($task);

    // 403, not 404: the actor is in the workspace, only the project is out of reach.
    $this->actingAs($outsider)
        ->get(route('attachments.download', $attachment))
        ->assertForbidden();
});

it('lets a guest download what they were given', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Viewer)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();
    $attachment = storedAttachment($task);

    $this->actingAs($guest)->get(route('attachments.download', $attachment))->assertOk();
});

it('hides an attachment in another workspace behind a 404', function (): void {
    [$workspace] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $attachment = storedAttachment($task);
    $stranger = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    $this->actingAs($stranger)->get(route('attachments.download', $attachment))->assertNotFound();
});

it('stops serving a file that has been removed', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $attachment = storedAttachment($task);

    $attachment->file->delete();

    $this->actingAs($actor)->get(route('attachments.download', $attachment))->assertNotFound();
});

it('answers with a 404 when the row outlives the object', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $attachment = storedAttachment($task);

    Storage::disk($attachment->file->disk)->delete($attachment->file->path);

    $this->actingAs($actor)->get(route('attachments.download', $attachment))->assertNotFound();
});

it('turns away everybody who is not signed in', function (): void {
    [$workspace] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $attachment = storedAttachment($task);

    $this->get(route('attachments.download', $attachment))->assertRedirect(route('login'));
    $this->post(route('tasks.attachments.store', $task))->assertRedirect(route('login'));
});

it('keeps neither rows nor objects when a later file in the batch fails', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $created = 0;

    Attachment::creating(function (Attachment $attachment) use (&$created): void {
        if (++$created === 3) {
            $attachment->file_id = (string) Str::uuid7();
        }
    });

    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->post(route('tasks.attachments.store', $task), [
            'files' => [
                UploadedFile::fake()->create('first.pdf', 12, 'application/pdf'),
                UploadedFile::fake()->create('second.pdf', 12, 'application/pdf'),
                UploadedFile::fake()->create('third.pdf', 12, 'application/pdf'),
            ],
        ])
        ->assertServerError();

    expect($task->attachments()->count())->toBe(0)
        ->and(File::query()->count())->toBe(0)
        ->and(Storage::disk(config('filesystems.attachments'))->allFiles())->toBe([]);
});
