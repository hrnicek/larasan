<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/*
 * Project access against every operation that changes a task, over HTTP.
 *
 * `TaskMatrixTest` crosses the workspace roles with the same endpoints, and every task it
 * builds sits in no project at all — so until this file existed, the project half of the rule
 * was never asked about a task. ADR-0006 says an Editor may modify tasks, a Commenter may not
 * and a Viewer may do nothing; the endpoints agreed with none of that.
 *
 * Outcomes are written out rather than derived from the policy, which would assert only that
 * the code agrees with itself.
 */

/**
 * A task that lives in exactly one project, and an actor holding the given access to it.
 *
 * @return array{Task, User, Project}
 */
function taskInProjectFor(
    ?ProjectAccessLevel $access,
    ProjectVisibility $visibility = ProjectVisibility::Workspace,
    bool $archived = false,
): array {
    [$project, $actor] = projectFor(WorkspaceRole::Member, $access, $visibility);

    $task = Task::factory()->in($project->workspace)->create(['title' => 'Untouched']);
    TaskProjectMembership::factory()->placing($task, $project)->create();

    // Archived last: a placement cannot be made on a board that is already closed.
    if ($archived) {
        $project->forceFill(['archived_at' => now()])->save();
    }

    return [$task, $actor, $project];
}

/**
 * @return array{string, string, array<string, mixed>}
 */
function projectAccessOperation(string $operation, Task $task): array
{
    return match ($operation) {
        'update' => ['put', route('tasks.update', $task), ['title' => 'Renamed']],
        'complete' => ['put', route('tasks.complete', $task), []],
        'assign' => ['put', route('tasks.assign', $task), []],
        'delete' => ['delete', route('tasks.destroy', $task), []],
        'comment' => ['post', route('tasks.comments.store', $task), ['body' => 'Said something']],
        default => throw new InvalidArgumentException("Unknown operation [{$operation}]."),
    };
}

it('answers each project access level the same way at every task endpoint', function (
    ?ProjectAccessLevel $access,
    string $operation,
    string $outcome,
): void {
    [$task, $actor] = taskInProjectFor($access);

    [$method, $url, $payload] = projectAccessOperation($operation, $task);

    $response = $this->actingAs($actor)->{$method}($url, $payload);

    match ($outcome) {
        'allowed' => expect($response->status())->toBeIn([200, 302]),
        'forbidden' => $response->assertForbidden(),
        default => throw new InvalidArgumentException("Unknown outcome [{$outcome}]."),
    };

    if ($outcome !== 'allowed') {
        expect($task->fresh()?->title)->toBe('Untouched')
            ->and($task->fresh()?->isCompleted())->toBeFalse()
            ->and($task->fresh()?->comments()->count())->toBe(0);
    }
})->with([
    'owner update' => [ProjectAccessLevel::Owner, 'update', 'allowed'],
    'owner complete' => [ProjectAccessLevel::Owner, 'complete', 'allowed'],
    'owner assign' => [ProjectAccessLevel::Owner, 'assign', 'allowed'],
    'owner delete' => [ProjectAccessLevel::Owner, 'delete', 'allowed'],
    'owner comment' => [ProjectAccessLevel::Owner, 'comment', 'allowed'],

    'editor update' => [ProjectAccessLevel::Editor, 'update', 'allowed'],
    'editor complete' => [ProjectAccessLevel::Editor, 'complete', 'allowed'],
    'editor assign' => [ProjectAccessLevel::Editor, 'assign', 'allowed'],
    'editor delete' => [ProjectAccessLevel::Editor, 'delete', 'allowed'],
    'editor comment' => [ProjectAccessLevel::Editor, 'comment', 'allowed'],

    // The level that exists to let somebody take part without changing the work.
    'commenter update' => [ProjectAccessLevel::Commenter, 'update', 'forbidden'],
    'commenter complete' => [ProjectAccessLevel::Commenter, 'complete', 'forbidden'],
    'commenter assign' => [ProjectAccessLevel::Commenter, 'assign', 'forbidden'],
    'commenter delete' => [ProjectAccessLevel::Commenter, 'delete', 'forbidden'],
    'commenter comment' => [ProjectAccessLevel::Commenter, 'comment', 'allowed'],

    'viewer update' => [ProjectAccessLevel::Viewer, 'update', 'forbidden'],
    'viewer complete' => [ProjectAccessLevel::Viewer, 'complete', 'forbidden'],
    'viewer assign' => [ProjectAccessLevel::Viewer, 'assign', 'forbidden'],
    'viewer delete' => [ProjectAccessLevel::Viewer, 'delete', 'forbidden'],
    'viewer comment' => [ProjectAccessLevel::Viewer, 'comment', 'forbidden'],

    /*
     * No membership row on a board the whole workspace can open. The project's default access
     * level answers for them, and it is `editor` — the same default Asana, ClickUp and Jira
     * ship. Pinned by `TaskPolicyTest` since TASK-070-017, and the reason the rest of this
     * matrix cannot simply require a row.
     */
    'workspace default update' => [null, 'update', 'allowed'],
    'workspace default complete' => [null, 'complete', 'allowed'],
    'workspace default comment' => [null, 'comment', 'allowed'],
]);

it('refuses every change to a task whose only board is archived', function (string $operation): void {
    [$task, $actor] = taskInProjectFor(ProjectAccessLevel::Owner, archived: true);

    [$method, $url, $payload] = projectAccessOperation($operation, $task);

    // An archived project is a record of what happened. `Project::allowsChangesBy()` has always
    // said so, and the placement endpoints have always honoured it; the task endpoints never
    // asked, so a closed board's cards stayed editable through the panel.
    $this->actingAs($actor)->{$method}($url, $payload)->assertForbidden();

    expect($task->fresh()?->title)->toBe('Untouched')
        ->and($task->fresh()?->isCompleted())->toBeFalse();
})->with(['update', 'complete', 'assign', 'delete', 'comment']);

it('lets a task on a live board be changed while it is also on an archived one', function (): void {
    [$task, $actor, $archived] = taskInProjectFor(ProjectAccessLevel::Owner, archived: true);

    $live = Project::factory()->in($archived->workspace)->create(['visibility' => ProjectVisibility::Workspace]);
    TaskProjectMembership::factory()->placing($task, $live)->create();

    // One task, several boards (ADR-0003). Reaching one that is open is enough, the same way
    // reaching one that is visible is enough to read it.
    $this->actingAs($actor)
        ->put(route('tasks.update', $task), ['title' => 'Renamed'])
        ->assertRedirect();

    expect($task->fresh()?->title)->toBe('Renamed');
});

it('refuses to tag a task in a project the actor may only read', function (): void {
    [$task, $actor, $project] = taskInProjectFor(ProjectAccessLevel::Viewer);
    $tag = Tag::factory()->in($project->workspace)->create();

    $this->actingAs($actor)
        ->post(route('tasks.tags.store', $task), ['tag' => $tag->id])
        ->assertForbidden();

    expect($task->tags()->count())->toBe(0);
});

it('refuses to attach a file to a task in a project the actor may only read', function (): void {
    [$task, $actor] = taskInProjectFor(ProjectAccessLevel::Viewer);

    $this->actingAs($actor)
        ->post(route('tasks.attachments.store', $task), ['file' => UploadedFile::fake()->create('notes.txt', 8)])
        ->assertForbidden();

    expect($task->attachments()->count())->toBe(0);
});

it('hides a task whose only board is a private project the actor was not given', function (): void {
    [$task, $actor] = taskInProjectFor(null, ProjectVisibility::Private);

    // Reach is settled before access level is worth asking: this is `TaskPolicy::view()`
    // refusing, and it must refuse at the write endpoints too.
    $this->actingAs($actor)->put(route('tasks.update', $task), ['title' => 'Renamed'])->assertForbidden();

    expect($task->fresh()?->title)->toBe('Untouched');
});
