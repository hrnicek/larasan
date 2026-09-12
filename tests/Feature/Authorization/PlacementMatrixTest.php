<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Str;

// All three endpoints require `task.update` on the project; detaching removes a placement, not the task.

/**
 * @return array{TaskProjectMembership, User, Project, Section, Task}
 */
function matrixPlacement(WorkspaceRole $role, ?ProjectAccessLevel $access): array
{
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Workspace]);

    if ($access !== null) {
        ProjectMembership::factory()->in($project)->forUser($actor)->withAccess($access)->create();
    }

    $section = Section::factory()->in($project)->create(['name' => 'Doing']);
    $placement = TaskProjectMembership::factory()
        ->placing(Task::factory()->in($workspace)->create(), $project)
        ->create();

    return [$placement, $actor, $project, $section, Task::factory()->in($workspace)->create()];
}

it('answers each role and access level the same way at every placement endpoint', function (
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
    string $operation,
    string $outcome,
): void {
    [$placement, $actor, $project, $section, $unplaced] = matrixPlacement($role, $access);

    [$method, $url, $payload] = match ($operation) {
        'attach' => ['post', route('placements.store', $project), ['task' => $unplaced->id]],
        'move' => ['put', route('placements.move', $placement), ['section' => $section->id]],
        'detach' => ['delete', route('placements.destroy', $placement), []],
        default => throw new InvalidArgumentException("Unknown matrix operation [{$operation}]."),
    };

    $response = $this->actingAs($actor)->{$method}($url, $payload);

    match ($outcome) {
        'allowed' => expect($response->status())->toBeIn([200, 302]),
        'forbidden' => $response->assertForbidden(),
        'missing' => $response->assertNotFound(),
        default => throw new InvalidArgumentException("Unknown outcome [{$outcome}]."),
    };

    if ($outcome !== 'allowed') {
        expect($project->placements()->count())->toBe(1)
            ->and($placement->fresh()?->section_id)->toBeNull();
    }
})->with([
    'owner as owner attach' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'attach', 'allowed'],
    'owner as owner move' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'move', 'allowed'],
    'owner as owner detach' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'detach', 'allowed'],
    'owner as editor attach' => [WorkspaceRole::Owner, ProjectAccessLevel::Editor, 'attach', 'allowed'],
    'owner as editor move' => [WorkspaceRole::Owner, ProjectAccessLevel::Editor, 'move', 'allowed'],
    'owner as editor detach' => [WorkspaceRole::Owner, ProjectAccessLevel::Editor, 'detach', 'allowed'],
    'owner as commenter attach' => [WorkspaceRole::Owner, ProjectAccessLevel::Commenter, 'attach', 'forbidden'],
    'owner as commenter move' => [WorkspaceRole::Owner, ProjectAccessLevel::Commenter, 'move', 'forbidden'],
    'owner as commenter detach' => [WorkspaceRole::Owner, ProjectAccessLevel::Commenter, 'detach', 'forbidden'],
    'owner as viewer attach' => [WorkspaceRole::Owner, ProjectAccessLevel::Viewer, 'attach', 'forbidden'],
    'owner as viewer move' => [WorkspaceRole::Owner, ProjectAccessLevel::Viewer, 'move', 'forbidden'],
    'owner as viewer detach' => [WorkspaceRole::Owner, ProjectAccessLevel::Viewer, 'detach', 'forbidden'],
    // Without a membership row the project's default access level, `editor`, applies; guests get no default. See ADR-0020.
    'owner as no member attach' => [WorkspaceRole::Owner, null, 'attach', 'allowed'],
    'owner as no member move' => [WorkspaceRole::Owner, null, 'move', 'allowed'],
    'owner as no member detach' => [WorkspaceRole::Owner, null, 'detach', 'allowed'],
    'admin as owner attach' => [WorkspaceRole::Admin, ProjectAccessLevel::Owner, 'attach', 'allowed'],
    'admin as owner move' => [WorkspaceRole::Admin, ProjectAccessLevel::Owner, 'move', 'allowed'],
    'admin as owner detach' => [WorkspaceRole::Admin, ProjectAccessLevel::Owner, 'detach', 'allowed'],
    'admin as editor attach' => [WorkspaceRole::Admin, ProjectAccessLevel::Editor, 'attach', 'allowed'],
    'admin as editor move' => [WorkspaceRole::Admin, ProjectAccessLevel::Editor, 'move', 'allowed'],
    'admin as editor detach' => [WorkspaceRole::Admin, ProjectAccessLevel::Editor, 'detach', 'allowed'],
    'admin as commenter attach' => [WorkspaceRole::Admin, ProjectAccessLevel::Commenter, 'attach', 'forbidden'],
    'admin as commenter move' => [WorkspaceRole::Admin, ProjectAccessLevel::Commenter, 'move', 'forbidden'],
    'admin as commenter detach' => [WorkspaceRole::Admin, ProjectAccessLevel::Commenter, 'detach', 'forbidden'],
    'admin as viewer attach' => [WorkspaceRole::Admin, ProjectAccessLevel::Viewer, 'attach', 'forbidden'],
    'admin as viewer move' => [WorkspaceRole::Admin, ProjectAccessLevel::Viewer, 'move', 'forbidden'],
    'admin as viewer detach' => [WorkspaceRole::Admin, ProjectAccessLevel::Viewer, 'detach', 'forbidden'],
    'admin as no member attach' => [WorkspaceRole::Admin, null, 'attach', 'allowed'],
    'admin as no member move' => [WorkspaceRole::Admin, null, 'move', 'allowed'],
    'admin as no member detach' => [WorkspaceRole::Admin, null, 'detach', 'allowed'],
    'member as owner attach' => [WorkspaceRole::Member, ProjectAccessLevel::Owner, 'attach', 'allowed'],
    'member as owner move' => [WorkspaceRole::Member, ProjectAccessLevel::Owner, 'move', 'allowed'],
    'member as owner detach' => [WorkspaceRole::Member, ProjectAccessLevel::Owner, 'detach', 'allowed'],
    'member as editor attach' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, 'attach', 'allowed'],
    'member as editor move' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, 'move', 'allowed'],
    'member as editor detach' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, 'detach', 'allowed'],
    'member as commenter attach' => [WorkspaceRole::Member, ProjectAccessLevel::Commenter, 'attach', 'forbidden'],
    'member as commenter move' => [WorkspaceRole::Member, ProjectAccessLevel::Commenter, 'move', 'forbidden'],
    'member as commenter detach' => [WorkspaceRole::Member, ProjectAccessLevel::Commenter, 'detach', 'forbidden'],
    'member as viewer attach' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'attach', 'forbidden'],
    'member as viewer move' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'move', 'forbidden'],
    'member as viewer detach' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'detach', 'forbidden'],
    'member as no member attach' => [WorkspaceRole::Member, null, 'attach', 'allowed'],
    'member as no member move' => [WorkspaceRole::Member, null, 'move', 'allowed'],
    'member as no member detach' => [WorkspaceRole::Member, null, 'detach', 'allowed'],
    'guest as owner attach' => [WorkspaceRole::Guest, ProjectAccessLevel::Owner, 'attach', 'forbidden'],
    'guest as owner move' => [WorkspaceRole::Guest, ProjectAccessLevel::Owner, 'move', 'forbidden'],
    'guest as owner detach' => [WorkspaceRole::Guest, ProjectAccessLevel::Owner, 'detach', 'forbidden'],
    'guest as editor attach' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'attach', 'forbidden'],
    'guest as editor move' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'move', 'forbidden'],
    'guest as editor detach' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'detach', 'forbidden'],
    'guest as commenter attach' => [WorkspaceRole::Guest, ProjectAccessLevel::Commenter, 'attach', 'forbidden'],
    'guest as commenter move' => [WorkspaceRole::Guest, ProjectAccessLevel::Commenter, 'move', 'forbidden'],
    'guest as commenter detach' => [WorkspaceRole::Guest, ProjectAccessLevel::Commenter, 'detach', 'forbidden'],
    'guest as viewer attach' => [WorkspaceRole::Guest, ProjectAccessLevel::Viewer, 'attach', 'forbidden'],
    'guest as viewer move' => [WorkspaceRole::Guest, ProjectAccessLevel::Viewer, 'move', 'forbidden'],
    'guest as viewer detach' => [WorkspaceRole::Guest, ProjectAccessLevel::Viewer, 'detach', 'forbidden'],
    'guest as no member attach' => [WorkspaceRole::Guest, null, 'attach', 'missing'],
    'guest as no member move' => [WorkspaceRole::Guest, null, 'move', 'missing'],
    'guest as no member detach' => [WorkspaceRole::Guest, null, 'detach', 'missing'], ]);

it('answers a card from another workspace exactly as it answers one that does not exist', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Owner);
    $project = Project::factory()->in($workspace)->create();
    ProjectMembership::factory()->in($project)->forUser($actor)->withAccess(ProjectAccessLevel::Owner)->create();

    $elsewhere = Task::factory()->in(Workspace::factory()->create())->create();
    $nobodys = (string) Str::uuid7();

    $foreign = $this->actingAs($actor)->post(route('placements.store', $project), ['task' => $elsewhere->id]);
    $missing = $this->actingAs($actor)->post(route('placements.store', $project), ['task' => $nobodys]);

    $foreign->assertSessionHasErrors('task');
    $missing->assertSessionHasErrors('task');

    // A foreign id must be indistinguishable from a missing one, or the endpoint is an existence oracle.
    expect($foreign->status())->toBe($missing->status())
        ->and(session('errors')?->get('task'))->not->toBeEmpty()
        ->and($project->placements()->count())->toBe(0);
})->with([
    'the validation rule is scoped to the project workspace, so both are simply invalid',
]);

it('refuses another workspace card even to somebody who owns a project there', function (): void {
    [$placement] = matrixPlacement(WorkspaceRole::Owner, ProjectAccessLevel::Owner);
    [, $outsider] = workspaceWith(WorkspaceRole::Owner);

    $this->actingAs($outsider)->delete(route('placements.destroy', $placement))->assertNotFound();

    expect($placement->fresh())->not->toBeNull();
});

it('answers a task hidden in a private project exactly as it answers one that does not exist', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $own = Project::factory()->in($workspace)->private()->create();
    ProjectMembership::factory()->in($own)->forUser($actor)->withAccess(ProjectAccessLevel::Owner)->create();

    $hidden = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()
        ->placing($hidden, Project::factory()->in($workspace)->private()->create())
        ->create();

    $unreachable = $this->actingAs($actor)->postJson(route('placements.store', $own), ['task' => $hidden->id]);
    $missing = $this->actingAs($actor)->postJson(route('placements.store', $own), ['task' => (string) Str::uuid7()]);

    $unreachable->assertUnprocessable()->assertJsonValidationErrorFor('task');
    $missing->assertUnprocessable()->assertJsonValidationErrorFor('task');

    expect($unreachable->status())->toBe($missing->status())
        ->and($unreachable->json('errors.task'))->toBe($missing->json('errors.task'))
        ->and($own->placements()->count())->toBe(0);

    $this->actingAs($actor)->getJson(route('tasks.show', $hidden))->assertForbidden();
});
