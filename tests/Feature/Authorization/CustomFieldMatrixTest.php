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

/**
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

    expect(CustomField::query()->pluck('name')->all())
        ->toBe($outcome === 'allowed' ? ['Estimate'] : ['Existing'])
        ->and($project->customFields()->count())->toBe(0);
})->with([
    // `custom_field.manage` belongs to owners and admins only, unlike `tag.manage`. See ADR-0010.
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
    // Setting a value is a task update, so the project access level applies.
    'owner as project owner' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'allowed'],
    'admin as commenter' => [WorkspaceRole::Admin, ProjectAccessLevel::Commenter, 'forbidden'],
    'member as viewer' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'forbidden'],
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

    // The `{field}` binding is workspace-scoped and resolves before any permission check, so even a guest gets 404.
    $this->actingAs($actor)
        ->put(route('tasks.custom-fields.update', [$task, $elsewhere]), ['value' => 'x'])
        ->assertNotFound();
})->with([
    'owner' => [WorkspaceRole::Owner],
    'admin' => [WorkspaceRole::Admin],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it("keeps one workspace's answers out of another's reach", function (): void {
    [$task, $field, $actor] = matrixFieldTask(WorkspaceRole::Owner, ProjectAccessLevel::Owner);
    $this->actingAs($actor)->put(route('tasks.custom-fields.update', [$task, $field]), ['value' => 'Two days']);

    $stranger = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

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
