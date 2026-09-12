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

/**
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
    // `tag.manage` belongs to every full member; guests hold only `comment.create`. See ADR-0010.
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
    // Applying a tag is a task update, so the project access level applies.
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

    // Permission is checked before the tag lookup, so a guest gets 403 and never learns whether the id exists.
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

    // A revoked member has no current workspace, so bound routes answer 404 before the policy runs.
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
