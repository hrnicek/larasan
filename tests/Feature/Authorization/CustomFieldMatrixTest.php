<?php

declare(strict_types=1);

use App\Domain\CustomField\Actions\AttachFieldToProject;
use App\Domain\CustomField\Actions\DefineCustomField;
use App\Domain\CustomField\Actions\DeleteCustomField;
use App\Domain\CustomField\Actions\RenameCustomField;
use App\Domain\CustomField\Exceptions\CustomFieldException;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\TaskCustomFieldValue;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\CustomFieldType;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/*
 * Two permissions again, and this time they differ from tags: `custom_field.manage` is an
 * owner's and an admin's, because a field is a column on everybody's screens, while a value is
 * an edit of one task. Outcomes are written out rather than derived from the code.
 */

/**
 * A field on a project, and a task in it, with somebody holding the given access.
 *
 * @return array{Task, CustomField, User}
 */
function matrixFieldTask(
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
    ProjectVisibility $visibility = ProjectVisibility::Workspace,
): array {
    $workspace = Workspace::factory()->create();
    $owner = memberOf($workspace, WorkspaceRole::Owner);
    $actor = memberOf($workspace, $role);
    $project = Project::factory()->in($workspace)->create(['visibility' => $visibility]);

    if ($access !== null) {
        ProjectMembership::factory()->in($project)->forUser($actor)->withAccess($access)->create();
    }

    $field = app(DefineCustomField::class)->handle($workspace, $owner, 'Estimate', CustomFieldType::Text);
    app(AttachFieldToProject::class)->handle($project, $field, $owner);

    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    return [$task, $field, $actor];
}

it('answers defining, renaming, attaching and deleting by role', function (WorkspaceRole $role, string $outcome): void {
    $workspace = Workspace::factory()->create();
    $owner = memberOf($workspace, WorkspaceRole::Owner);
    $actor = memberOf($workspace, $role);
    $project = Project::factory()->in($workspace)->create();
    $existing = app(DefineCustomField::class)->handle($workspace, $owner, 'Existing', CustomFieldType::Text);

    $operations = [
        fn () => app(DefineCustomField::class)->handle($workspace, $actor, 'Estimate', CustomFieldType::Text),
        fn () => app(RenameCustomField::class)->handle($existing, $actor, 'Renamed'),
        fn () => app(AttachFieldToProject::class)->handle($project, $existing, $actor),
        fn () => app(DeleteCustomField::class)->handle($existing, $actor),
    ];

    foreach ($operations as $operation) {
        if ($outcome === 'allowed') {
            $operation();

            continue;
        }

        expect($operation)->toThrow(CustomFieldException::class);
    }

    /*
     * The end state rather than a placeholder: allowed means the new field exists and the one
     * that was renamed, attached and then deleted is gone; forbidden means the workspace's
     * vocabulary is exactly what the owner left.
     */
    expect(CustomField::query()->pluck('name')->all())
        ->toBe($outcome === 'allowed' ? ['Estimate'] : ['Existing'])
        ->and($project->customFields()->count())->toBe(0);
})->with([
    /*
     * A field is a column on everybody's screens, so ADR-0010 keeps this with owners and admins
     * — unlike `tag.manage`, which every full member holds.
     */
    'owner' => [WorkspaceRole::Owner, 'allowed'],
    'admin' => [WorkspaceRole::Admin, 'allowed'],
    'member' => [WorkspaceRole::Member, 'forbidden'],
    'guest' => [WorkspaceRole::Guest, 'forbidden'],
]);

it('answers setting a value by role and project access', function (
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
    string $outcome,
): void {
    [$task, $field, $actor] = matrixFieldTask($role, $access);

    $response = $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->put(route('tasks.custom-fields.update', [$task, $field]), ['value' => 'Two days']);

    match ($outcome) {
        'allowed' => expect($response->status())->toBeIn([200, 302]),
        'forbidden' => $response->assertForbidden(),
        default => throw new InvalidArgumentException("Unknown outcome [{$outcome}]."),
    };

    expect(TaskCustomFieldValue::query()->count())->toBe($outcome === 'allowed' ? 1 : 0);
})->with([
    // Filling a field in is editing the task, so this is `task.update` and reach — every full
    // member, whatever their project access, and no guest.
    'owner as project owner' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'allowed'],
    'admin as commenter' => [WorkspaceRole::Admin, ProjectAccessLevel::Commenter, 'allowed'],
    'member as viewer' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'allowed'],
    'member with no project membership' => [WorkspaceRole::Member, null, 'allowed'],
    'guest given the project' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'forbidden'],
    'guest given nothing' => [WorkspaceRole::Guest, null, 'forbidden'],
]);

it('refuses a value on a task in a private project the actor was not given', function (WorkspaceRole $role): void {
    [$task, $field, $actor] = matrixFieldTask($role, null, ProjectVisibility::Private);

    $this->actingAs($actor)
        ->put(route('tasks.custom-fields.update', [$task, $field]), ['value' => 'Two days'])
        ->assertForbidden();

    expect(TaskCustomFieldValue::query()->count())->toBe(0);
})->with([
    'owner' => [WorkspaceRole::Owner],
    'admin' => [WorkspaceRole::Admin],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it('hides a field from another workspace behind a 404 for every role', function (WorkspaceRole $role): void {
    [$task, , $actor] = matrixFieldTask($role, ProjectAccessLevel::Editor);
    $elsewhere = CustomField::factory()->create();

    /*
     * 404 for everybody, a guest included: `{field}` is a route binding scoped to the current
     * workspace, so it answers before any permission is asked. The tag matrix records the other
     * shape — there the tag is looked up inside the controller, after the Gate, so a guest gets
     * a 403. Both are right; the difference is where the lookup happens, and writing both down
     * is what keeps that a decision rather than an accident.
     */
    $this->actingAs($actor)
        ->put(route('tasks.custom-fields.update', [$task, $elsewhere]), ['value' => 'x'])
        ->assertNotFound();
})->with([
    'owner' => [WorkspaceRole::Owner],
    'admin' => [WorkspaceRole::Admin],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it('keeps one workspace s answers out of another s reach', function (): void {
    [$task, $field, $actor] = matrixFieldTask(WorkspaceRole::Owner, ProjectAccessLevel::Owner);
    $this->actingAs($actor)->put(route('tasks.custom-fields.update', [$task, $field]), ['value' => 'Two days']);

    $stranger = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    // The answers ride on the task, so another tenant cannot read them without reading the task.
    $this->actingAs($stranger)->get(route('tasks.show', $task))->assertNotFound();
});

it('refuses everybody whose membership is no longer live', function (WorkspaceRole $role): void {
    $workspace = Workspace::factory()->create();
    $owner = memberOf($workspace, WorkspaceRole::Owner);
    $revoked = memberOf($workspace, $role, WorkspaceMembershipStatus::Revoked);
    $field = app(DefineCustomField::class)->handle($workspace, $owner, 'Estimate', CustomFieldType::Text);
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($revoked)
        ->put(route('tasks.custom-fields.update', [$task, $field]), ['value' => 'Two days'])
        ->assertNotFound();

    expect(fn (): CustomField => app(RenameCustomField::class)->handle($field, $revoked, 'Mine'))
        ->toThrow(CustomFieldException::class);
})->with([
    'owner' => [WorkspaceRole::Owner],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it('turns away everybody who is not signed in', function (): void {
    $workspace = Workspace::factory()->create();
    $field = CustomField::factory()->in($workspace)->create();
    $task = Task::factory()->in($workspace)->create();

    $this->put(route('tasks.custom-fields.update', [$task, $field]), ['value' => 'x'])
        ->assertRedirect(route('login'));
});
