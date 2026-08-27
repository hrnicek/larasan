<?php

declare(strict_types=1);

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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Every workspace role against every project access level, over uploading, downloading and
 * removing. The policy tests prove the rules; this proves the endpoints ask them, and that a
 * refusal is the right kind — 404 where the actor may not know the thing exists, 403 where they
 * may know and still not act.
 *
 * Outcomes are written out rather than derived from the policy, which would assert only that
 * the code agrees with itself.
 */

beforeEach(function (): void {
    Storage::fake(config('filesystems.attachments'));
});

/**
 * A task in one project, and somebody with the given access to it.
 *
 * @return array{Task, User, Project}
 */
function matrixFileTask(
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
    ProjectVisibility $visibility = ProjectVisibility::Workspace,
): array {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);
    $project = Project::factory()->in($workspace)->create(['visibility' => $visibility]);

    if ($access !== null) {
        ProjectMembership::factory()->in($project)->forUser($actor)->withAccess($access)->create();
    }

    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    return [$task, $actor, $project];
}

function matrixAttachment(Task $task, ?User $uploader = null): Attachment
{
    $file = $uploader === null
        ? File::factory()->in($task->workspace)->create()
        : File::factory()->in($task->workspace)->by($uploader)->create();

    Storage::disk($file->disk)->put($file->path, 'the contents');

    return Attachment::factory()->attaching($file, $task)->create();
}

it('answers uploading the same way for each role and access level', function (
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
    string $outcome,
): void {
    [$task, $actor] = matrixFileTask($role, $access);

    $response = $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->post(route('tasks.attachments.store', $task), [
            'file' => UploadedFile::fake()->create('plan.pdf', 12, 'application/pdf'),
        ]);

    match ($outcome) {
        'allowed' => expect($response->status())->toBeIn([200, 302]),
        'forbidden' => $response->assertForbidden(),
        default => throw new InvalidArgumentException("Unknown outcome [{$outcome}]."),
    };

    expect($task->attachments()->count())->toBe($outcome === 'allowed' ? 1 : 0);
})->with([
    /*
     * `file.upload` is every full member's and no guest's (ADR-0010). Within that, the board
     * still answers: hanging a document on a card is changing the card, so a Viewer and a
     * Commenter are refused here and can still open what is already attached — which is the
     * dataset further down, and the difference between reading and writing.
     */
    'owner as project owner' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'allowed'],
    'owner as viewer' => [WorkspaceRole::Owner, ProjectAccessLevel::Viewer, 'forbidden'],
    'admin as editor' => [WorkspaceRole::Admin, ProjectAccessLevel::Editor, 'allowed'],
    'admin as commenter' => [WorkspaceRole::Admin, ProjectAccessLevel::Commenter, 'forbidden'],
    'member as owner' => [WorkspaceRole::Member, ProjectAccessLevel::Owner, 'allowed'],
    'member as editor' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, 'allowed'],
    'member as commenter' => [WorkspaceRole::Member, ProjectAccessLevel::Commenter, 'forbidden'],
    'member as viewer' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'forbidden'],
    'member with no project membership' => [WorkspaceRole::Member, null, 'allowed'],

    // A guest may say things about what they were given; adding documents to it is not theirs.
    'guest as owner' => [WorkspaceRole::Guest, ProjectAccessLevel::Owner, 'forbidden'],
    'guest as editor' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'forbidden'],
    'guest as viewer' => [WorkspaceRole::Guest, ProjectAccessLevel::Viewer, 'forbidden'],
    'guest with no project membership' => [WorkspaceRole::Guest, null, 'forbidden'],
]);

it('refuses an upload to a task in a private project the actor was not given', function (
    WorkspaceRole $role,
): void {
    [$task, $actor] = matrixFileTask($role, null, ProjectVisibility::Private);

    // 403 rather than 404: they can see the workspace, so what is refused is the access and not
    // the task's existence (TASK-070-017).
    $this->actingAs($actor)
        ->post(route('tasks.attachments.store', $task), [
            'file' => UploadedFile::fake()->create('plan.pdf', 12, 'application/pdf'),
        ])
        ->assertForbidden();

    expect($task->attachments()->count())->toBe(0);
})->with([
    'owner' => [WorkspaceRole::Owner],
    'admin' => [WorkspaceRole::Admin],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it('answers downloading by reach alone', function (
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
    string $outcome,
): void {
    [$task, $actor] = matrixFileTask($role, $access);
    $attachment = matrixAttachment($task);

    $response = $this->actingAs($actor)->get(route('attachments.download', $attachment));

    match ($outcome) {
        'allowed' => $response->assertOk(),
        'forbidden' => $response->assertForbidden(),
        default => throw new InvalidArgumentException("Unknown outcome [{$outcome}]."),
    };
})->with([
    // Reading is reading: whoever can open the task can open what is attached to it, whatever
    // their role and whatever they may not do to it.
    'owner as viewer' => [WorkspaceRole::Owner, ProjectAccessLevel::Viewer, 'allowed'],
    'admin as commenter' => [WorkspaceRole::Admin, ProjectAccessLevel::Commenter, 'allowed'],
    'member as viewer' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'allowed'],
    'member with no project membership' => [WorkspaceRole::Member, null, 'allowed'],
    'guest given the project' => [WorkspaceRole::Guest, ProjectAccessLevel::Viewer, 'allowed'],
    // A guest holds only what they were given, and nobody gave them this.
    'guest given nothing' => [WorkspaceRole::Guest, null, 'forbidden'],
]);

it('refuses a leaked attachment id from a private project for every role', function (
    WorkspaceRole $role,
): void {
    [$task, $actor] = matrixFileTask($role, null, ProjectVisibility::Private);
    $attachment = matrixAttachment($task);

    /*
     * The reason a download is a controller and not a URL from the disk: neither a storage path
     * nor an id is a capability (ADR-0007).
     */
    $this->actingAs($actor)->get(route('attachments.download', $attachment))->assertForbidden();
})->with([
    'owner' => [WorkspaceRole::Owner],
    'admin' => [WorkspaceRole::Admin],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it('answers removing by the upload or the capability', function (
    WorkspaceRole $role,
    ProjectAccessLevel $access,
    bool $uploader,
    string $outcome,
): void {
    [$task, $actor] = matrixFileTask($role, $access);
    $attachment = matrixAttachment($task, $uploader ? $actor : null);

    $response = $this->actingAs($actor)->delete(route('attachments.destroy', $attachment));

    match ($outcome) {
        'allowed' => expect($response->status())->toBeIn([200, 302]),
        'forbidden' => $response->assertForbidden(),
        default => throw new InvalidArgumentException("Unknown outcome [{$outcome}]."),
    };

    expect($task->attachments()->count())->toBe($outcome === 'allowed' ? 0 : 1);
})->with([
    /*
     * `file.delete` is every full member's — a workspace moderates itself — so the uploader
     * question only decides the answer for a guest, who holds neither it nor an upload of their
     * own, since they cannot upload at all.
     */
    'owner, somebody else s' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, false, 'allowed'],
    'admin, somebody else s' => [WorkspaceRole::Admin, ProjectAccessLevel::Editor, false, 'allowed'],
    'member, somebody else s' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, false, 'allowed'],
    'member, their own' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, true, 'allowed'],
    'guest given the project' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, false, 'forbidden'],
]);

it('hides a file in another workspace behind a 404 for every role', function (WorkspaceRole $role): void {
    [$task] = matrixFileTask(WorkspaceRole::Owner, ProjectAccessLevel::Owner);
    $attachment = matrixAttachment($task);
    $stranger = memberOf(Workspace::factory()->create(), $role);

    // Another tenant's documents are not theirs to know about at all.
    $this->actingAs($stranger)->get(route('attachments.download', $attachment))->assertNotFound();
    $this->actingAs($stranger)->delete(route('attachments.destroy', $attachment))->assertNotFound();
    $this->actingAs($stranger)
        ->post(route('tasks.attachments.store', $task), ['file' => UploadedFile::fake()->create('plan.pdf', 12, 'application/pdf')])
        ->assertNotFound();
})->with([
    'owner' => [WorkspaceRole::Owner],
    'admin' => [WorkspaceRole::Admin],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it('refuses everybody whose workspace membership is no longer live', function (WorkspaceRole $role): void {
    $workspace = Workspace::factory()->create();
    $revoked = memberOf($workspace, $role, WorkspaceMembershipStatus::Revoked);
    $task = Task::factory()->in($workspace)->create();
    $attachment = matrixAttachment($task, $revoked);

    // No current workspace, so the binding answers before the policy does — and having uploaded
    // something is not a way back into a workspace somebody was removed from.
    $this->actingAs($revoked)->get(route('attachments.download', $attachment))->assertNotFound();
    $this->actingAs($revoked)->delete(route('attachments.destroy', $attachment))->assertNotFound();
})->with([
    'owner' => [WorkspaceRole::Owner],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);
