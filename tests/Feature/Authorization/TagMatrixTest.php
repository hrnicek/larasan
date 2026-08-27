<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Actions\FollowTask;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/*
 * Two permissions, asserted apart: `tag.manage` makes and unmakes the workspace's vocabulary,
 * and `task.update` decides who may put a word on a piece of work. Outcomes are written out
 * rather than derived from the code.
 */

/**
 * A task in one project, and somebody with the given access to it.
 *
 * @return array{Task, User, Tag}
 */
function matrixTagTask(
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

    return [$task, $actor, Tag::factory()->in($workspace)->create()];
}

it('answers making, renaming and deleting a tag by role', function (WorkspaceRole $role, string $outcome): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);
    $existing = Tag::factory()->in($workspace)->named('Existing')->create();

    $created = $this->actingAs($actor)->post(route('tags.store'), ['name' => 'Bug']);
    $renamed = $this->actingAs($actor)->put(route('tags.update', $existing), ['name' => 'Renamed']);
    $deleted = $this->actingAs($actor)->delete(route('tags.destroy', $existing));

    match ($outcome) {
        'allowed' => expect([$created->status(), $renamed->status(), $deleted->status()])
            ->each->toBeIn([200, 302]),
        'forbidden' => expect([$created->status(), $renamed->status(), $deleted->status()])
            ->each->toBe(403),
        default => throw new InvalidArgumentException("Unknown outcome [{$outcome}]."),
    };
})->with([
    /*
     * `tag.manage` is every full member's under ADR-0010: a workspace names its own work rather
     * than waiting for an administrator. A guest holds only `comment.create`.
     */
    'owner' => [WorkspaceRole::Owner, 'allowed'],
    'admin' => [WorkspaceRole::Admin, 'allowed'],
    'member' => [WorkspaceRole::Member, 'allowed'],
    'guest' => [WorkspaceRole::Guest, 'forbidden'],
]);

it('answers applying a tag by role and project access', function (
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
    string $outcome,
): void {
    [$task, $actor, $tag] = matrixTagTask($role, $access);

    $response = $this->actingAs($actor)->post(route('tasks.tags.store', $task), ['tag' => $tag->id]);

    match ($outcome) {
        'allowed' => expect($response->status())->toBeIn([200, 302]),
        'forbidden' => $response->assertForbidden(),
        default => throw new InvalidArgumentException("Unknown outcome [{$outcome}]."),
    };

    expect($task->tags()->count())->toBe($outcome === 'allowed' ? 1 : 0);
})->with([
    // Putting a word on a piece of work is editing that work, so this is `task.update` — and it
    // gives the same answer every other edit gives, the board's access level included.
    'owner as project owner' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'allowed'],
    'owner as viewer' => [WorkspaceRole::Owner, ProjectAccessLevel::Viewer, 'forbidden'],
    'admin as editor' => [WorkspaceRole::Admin, ProjectAccessLevel::Editor, 'allowed'],
    'member as viewer' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'forbidden'],
    'member with no project membership' => [WorkspaceRole::Member, null, 'allowed'],
    'guest given the project' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'forbidden'],
    'guest given nothing' => [WorkspaceRole::Guest, null, 'forbidden'],
]);

it('refuses tagging a task in a private project the actor was not given', function (WorkspaceRole $role): void {
    [$task, $actor, $tag] = matrixTagTask($role, null, ProjectVisibility::Private);

    $this->actingAs($actor)
        ->post(route('tasks.tags.store', $task), ['tag' => $tag->id])
        ->assertForbidden();

    expect($task->tags()->count())->toBe(0);
})->with([
    'owner' => [WorkspaceRole::Owner],
    'admin' => [WorkspaceRole::Admin],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it('hides a tag from another workspace behind a 404 for every role', function (
    WorkspaceRole $role,
    int $applying,
): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);
    $task = Task::factory()->in($workspace)->create();
    $elsewhere = Tag::factory()->create();

    $this->actingAs($actor)->put(route('tags.update', $elsewhere), ['name' => 'Mine'])->assertNotFound();
    $this->actingAs($actor)->delete(route('tags.destroy', $elsewhere))->assertNotFound();

    /*
     * Applying it answers 404 for anybody who may edit the task and 403 for a guest, who may
     * not — because permission is asked before the tag is looked up. A guest therefore never
     * learns whether that id is a tag at all, which is the stronger of the two answers.
     */
    $this->actingAs($actor)
        ->post(route('tasks.tags.store', $task), ['tag' => $elsewhere->id])
        ->assertStatus($applying);
})->with([
    'owner' => [WorkspaceRole::Owner, 404],
    'admin' => [WorkspaceRole::Admin, 404],
    'member' => [WorkspaceRole::Member, 404],
    'guest' => [WorkspaceRole::Guest, 403],
]);

it('refuses everybody whose membership is no longer live', function (WorkspaceRole $role): void {
    $workspace = Workspace::factory()->create();
    $revoked = memberOf($workspace, $role, WorkspaceMembershipStatus::Revoked);
    $tag = Tag::factory()->in($workspace)->create();
    $task = Task::factory()->in($workspace)->create();

    // No live membership is no current workspace, so the binding answers before the policy does.
    $this->actingAs($revoked)->post(route('tags.store'), ['name' => 'Bug'])->assertForbidden();
    $this->actingAs($revoked)->put(route('tags.update', $tag), ['name' => 'Mine'])->assertNotFound();
    $this->actingAs($revoked)->post(route('tasks.tags.store', $task), ['tag' => $tag->id])->assertNotFound();
})->with([
    'owner' => [WorkspaceRole::Owner],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it('keeps a task s followers out of another tenant s reach', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $stranger = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    app(FollowTask::class)->handle($task, $actor);

    // The follower list rides on the task, so another tenant cannot read it without reading the
    // task — which is a 404.
    $this->actingAs($stranger)->get(route('tasks.show', $task))->assertNotFound();
    $this->actingAs($stranger)->post(route('tasks.follow', $task))->assertNotFound();
});

it('turns away everybody who is not signed in', function (): void {
    $workspace = Workspace::factory()->create();
    $tag = Tag::factory()->in($workspace)->create();

    $this->post(route('tags.store'), ['name' => 'Bug'])->assertRedirect(route('login'));
    $this->put(route('tags.update', $tag), ['name' => 'Bug'])->assertRedirect(route('login'));
    $this->delete(route('tags.destroy', $tag))->assertRedirect(route('login'));
});
