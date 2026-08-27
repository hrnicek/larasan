<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Requests\File\StoreAttachmentRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;

/**
 * The endpoints arrive with TASK-120-006. A probe route asserts the request for what it is —
 * validation and authorization — before a controller exists to confuse a failure with a routing
 * one.
 */
beforeEach(function (): void {
    Route::middleware('web')->post('attachment-probe/{task}', fn (StoreAttachmentRequest $request, Task $task) => response()->json([
        'names' => collect((array) $request->file('files', []))->map(fn (UploadedFile $upload): string => $upload->getClientOriginalName())->all(),
        'subject' => $request->subject()?->getKey(),
    ]));
});

it('accepts a file from somebody who may upload and can reach the task', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->postJson("attachment-probe/{$task->id}", ['files' => [UploadedFile::fake()->create('plan.pdf', 12, 'application/pdf')]])
        ->assertOk()
        ->assertJson(['names' => ['plan.pdf'], 'subject' => $task->id]);
});

it('refuses a file larger than the configured bound', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    config(['attachments.max_kilobytes' => 100]);

    // The bound is configuration rather than a number in a rules array, so the same figure can
    // be shown to the person doing the uploading.
    $this->actingAs($actor)
        ->postJson("attachment-probe/{$task->id}", ['files' => [UploadedFile::fake()->create('big.pdf', 200, 'application/pdf')]])
        ->assertJsonValidationErrorFor('files.0');
});

it('refuses a type that is not on the list', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    /*
     * An allow-list, and `mimetypes` rather than `mimes`: the rule reads the file instead of
     * believing the extension, so a script that calls itself a PDF is still a script.
     */
    $this->actingAs($actor)
        ->postJson("attachment-probe/{$task->id}", [
            // Named like a document and typed like a program: the rule reads the file rather
            // than the extension, so the name buys nothing.
            'files' => [UploadedFile::fake()->create('payload.pdf', 10, 'application/x-msdownload')],
        ])
        ->assertJsonValidationErrorFor('files.0')
        // Named rather than numbered: the message says which of the chosen files was refused.
        ->assertJsonFragment(['files.0' => ['payload.pdf cannot be attached here.']]);
});

it('requires a file at all', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->postJson("attachment-probe/{$task->id}", [])
        ->assertJsonValidationErrorFor('files');
});

it('refuses a guest, who may comment but may not upload', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Editor)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    $this->actingAs($guest)
        ->postJson("attachment-probe/{$task->id}", ['files' => [UploadedFile::fake()->create('plan.pdf', 12, 'application/pdf')]])
        ->assertForbidden();
});

it('refuses somebody who cannot reach the task', function (): void {
    $workspace = Workspace::factory()->create();
    $outsider = memberOf($workspace, WorkspaceRole::Member);
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();

    // Reach again: the capability alone would let somebody put a document into a project they
    // were never given.
    $this->actingAs($outsider)
        ->postJson("attachment-probe/{$task->id}", ['files' => [UploadedFile::fake()->create('plan.pdf', 12, 'application/pdf')]])
        ->assertForbidden();
});
