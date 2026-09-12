<?php

declare(strict_types=1);

use App\Domain\Comment\Models\Comment;
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

/**
 * @return array{Task, User, Project}
 */
function matrixCommentTask(
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

it('answers writing a comment the same way for each role and access level', function (
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
    string $outcome,
): void {
    [$task, $actor] = matrixCommentTask($role, $access);

    $response = $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->post(route('tasks.comments.store', $task), ['body' => 'Looks right to me']);

    match ($outcome) {
        'allowed' => expect($response->status())->toBeIn([200, 302]),
        'forbidden' => $response->assertForbidden(),
        default => throw new InvalidArgumentException("Unknown outcome [{$outcome}]."),
    };

    expect($task->comments()->count())->toBe($outcome === 'allowed' ? 1 : 0);
})->with([
    // Every role holds `comment.create`; the project access level then refuses Viewers. See ADR-0006.
    'owner as project owner' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'allowed'],
    'owner as viewer' => [WorkspaceRole::Owner, ProjectAccessLevel::Viewer, 'forbidden'],
    'admin as editor' => [WorkspaceRole::Admin, ProjectAccessLevel::Editor, 'allowed'],
    'admin as commenter' => [WorkspaceRole::Admin, ProjectAccessLevel::Commenter, 'allowed'],
    'member as owner' => [WorkspaceRole::Member, ProjectAccessLevel::Owner, 'allowed'],
    'member as editor' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, 'allowed'],
    'member as commenter' => [WorkspaceRole::Member, ProjectAccessLevel::Commenter, 'allowed'],
    'member as viewer' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'forbidden'],
    'member with no project membership' => [WorkspaceRole::Member, null, 'allowed'],

    'guest as owner' => [WorkspaceRole::Guest, ProjectAccessLevel::Owner, 'allowed'],
    'guest as editor' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'allowed'],
    'guest as commenter' => [WorkspaceRole::Guest, ProjectAccessLevel::Commenter, 'allowed'],
    'guest as viewer' => [WorkspaceRole::Guest, ProjectAccessLevel::Viewer, 'forbidden'],
    'guest with no project membership' => [WorkspaceRole::Guest, null, 'forbidden'],
]);

it('refuses a comment on a task that lives only in a private project the actor was not given', function (
    WorkspaceRole $role,
): void {
    [$task, $actor] = matrixCommentTask($role, null, ProjectVisibility::Private);

    // 403, not 404: the task belongs to the actor's own workspace.
    $this->actingAs($actor)
        ->post(route('tasks.comments.store', $task), ['body' => 'Looks right to me'])
        ->assertForbidden();

    expect($task->comments()->count())->toBe(0);
})->with([
    'owner' => [WorkspaceRole::Owner],
    'admin' => [WorkspaceRole::Admin],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it('answers editing a comment by authorship and the right to comment', function (
    WorkspaceRole $role,
    ProjectAccessLevel $access,
    bool $author,
    string $outcome,
): void {
    [$task, $actor] = matrixCommentTask($role, $access);
    $comment = $author
        ? Comment::factory()->on($task)->by($actor)->create(['body' => 'Untouched'])
        : Comment::factory()->on($task)->create(['body' => 'Untouched']);

    $response = $this->actingAs($actor)->put(route('comments.update', $comment), ['body' => 'Rewritten']);

    match ($outcome) {
        'allowed' => expect($response->status())->toBeIn([200, 302]),
        'forbidden' => $response->assertForbidden(),
        default => throw new InvalidArgumentException("Unknown outcome [{$outcome}]."),
    };

    expect($comment->fresh()?->body)->toBe($outcome === 'allowed' ? 'Rewritten' : 'Untouched');
})->with([
    'owner, their own' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, true, 'allowed'],
    'owner, somebody else s' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, false, 'forbidden'],
    'admin, somebody else s' => [WorkspaceRole::Admin, ProjectAccessLevel::Editor, false, 'forbidden'],
    'member, their own' => [WorkspaceRole::Member, ProjectAccessLevel::Commenter, true, 'allowed'],
    'member, their own, as viewer' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, true, 'forbidden'],
    'member, somebody else s' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, false, 'forbidden'],
    'guest, their own' => [WorkspaceRole::Guest, ProjectAccessLevel::Commenter, true, 'allowed'],
    'guest, their own, as viewer' => [WorkspaceRole::Guest, ProjectAccessLevel::Viewer, true, 'forbidden'],
    'guest, somebody else s' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, false, 'forbidden'],
]);

it('answers deleting a comment by authorship or by the capability', function (
    WorkspaceRole $role,
    ProjectAccessLevel $access,
    bool $author,
    string $outcome,
): void {
    [$task, $actor] = matrixCommentTask($role, $access);
    $comment = $author
        ? Comment::factory()->on($task)->by($actor)->create()
        : Comment::factory()->on($task)->create();

    $response = $this->actingAs($actor)->delete(route('comments.destroy', $comment));

    match ($outcome) {
        'allowed' => expect($response->status())->toBeIn([200, 302]),
        'forbidden' => $response->assertForbidden(),
        default => throw new InvalidArgumentException("Unknown outcome [{$outcome}]."),
    };

    expect($comment->fresh()?->deleted_at === null)->toBe($outcome !== 'allowed');
})->with([
    // `comment.delete` belongs to every full member; a guest may only remove their own. See ADR-0010.
    'owner, somebody else s' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, false, 'allowed'],
    'admin, somebody else s' => [WorkspaceRole::Admin, ProjectAccessLevel::Editor, false, 'allowed'],
    'member, somebody else s' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, false, 'allowed'],
    'member, their own' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, true, 'allowed'],
    'guest, their own' => [WorkspaceRole::Guest, ProjectAccessLevel::Viewer, true, 'allowed'],
    'guest, somebody else s' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, false, 'forbidden'],
]);

it('hides a comment in another workspace behind a 404 for every role', function (WorkspaceRole $role): void {
    [$task] = matrixCommentTask(WorkspaceRole::Owner, ProjectAccessLevel::Owner);
    $comment = Comment::factory()->on($task)->create();
    $stranger = memberOf(Workspace::factory()->create(), $role);

    $this->actingAs($stranger)->put(route('comments.update', $comment), ['body' => 'Rewritten'])->assertNotFound();
    $this->actingAs($stranger)->delete(route('comments.destroy', $comment))->assertNotFound();
    $this->actingAs($stranger)->post(route('tasks.comments.store', $task), ['body' => 'Hello'])->assertNotFound();
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
    $comment = Comment::factory()->on($task)->create(['author_id' => $revoked->id]);

    // A revoked member has no current workspace, so the route binding answers 404 before the policy runs.
    $this->actingAs($revoked)->post(route('tasks.comments.store', $task), ['body' => 'Hello'])->assertNotFound();
    $this->actingAs($revoked)->put(route('comments.update', $comment), ['body' => 'Hello'])->assertNotFound();
    $this->actingAs($revoked)->delete(route('comments.destroy', $comment))->assertNotFound();
})->with([
    'owner' => [WorkspaceRole::Owner],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);
