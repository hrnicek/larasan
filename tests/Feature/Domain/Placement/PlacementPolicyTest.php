<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Placement\Policies\TaskProjectMembershipPolicy;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * A card in a project, and somebody with the given access to that project.
 *
 * @return array{TaskProjectMembership, User, Project}
 */
function cardSeenBy(
    ProjectAccessLevel $access = ProjectAccessLevel::Editor,
    WorkspaceRole $role = WorkspaceRole::Member,
    ProjectVisibility $visibility = ProjectVisibility::Workspace,
): array {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);
    $project = Project::factory()->in($workspace)->create(['visibility' => $visibility]);

    ProjectMembership::factory()->in($project)->forUser($actor)->withAccess($access)->create();

    $placement = TaskProjectMembership::factory()
        ->placing(Task::factory()->in($workspace)->create(), $project)
        ->create();

    return [$placement, $actor, $project];
}

it('is discovered as the policy for a placement', function (): void {
    expect(Gate::getPolicyFor(TaskProjectMembership::class))
        ->toBeInstanceOf(TaskProjectMembershipPolicy::class);
});

it('lets a project editor move and detach a card', function (): void {
    [$placement, $actor] = cardSeenBy(ProjectAccessLevel::Editor);

    expect($actor->can('view', $placement))->toBeTrue()
        ->and($actor->can('update', $placement))->toBeTrue()
        ->and($actor->can('delete', $placement))->toBeTrue();
});

it('lets a viewer read a card and change nothing', function (): void {
    [$placement, $actor] = cardSeenBy(ProjectAccessLevel::Viewer);

    expect($actor->can('view', $placement))->toBeTrue()
        ->and($actor->can('update', $placement))->toBeFalse()
        ->and($actor->can('delete', $placement))->toBeFalse();
});

it('lets a commenter read a card and change nothing', function (): void {
    [$placement, $actor] = cardSeenBy(ProjectAccessLevel::Commenter);

    // Commenting on a task is not rearranging the board.
    expect($actor->can('view', $placement))->toBeTrue()
        ->and($actor->can('update', $placement))->toBeFalse();
});

it('refuses a workspace member who is not in a private project', function (): void {
    $workspace = Workspace::factory()->create();
    $outsider = memberOf($workspace, WorkspaceRole::Member);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $placement = TaskProjectMembership::factory()
        ->placing(Task::factory()->in($workspace)->create(), $project)
        ->create();

    expect($outsider->can('view', $placement))->toBeFalse()
        ->and($outsider->can('update', $placement))->toBeFalse();
});

it('refuses a guest with no explicit project membership', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Workspace]);
    $placement = TaskProjectMembership::factory()
        ->placing(Task::factory()->in($workspace)->create(), $project)
        ->create();

    // Workspace visibility never reaches a guest (ADR-0006): they see what they were given.
    expect($guest->can('view', $placement))->toBeFalse()
        ->and($guest->can('update', $placement))->toBeFalse();
});

it('refuses a guest even where they are an explicit editor of the project', function (): void {
    [$placement, $guest] = cardSeenBy(ProjectAccessLevel::Editor, WorkspaceRole::Guest);

    // Reading is theirs — the project membership grants it. Moving a card is `task.update`,
    // which the guest role does not carry at workspace level (ADR-0010).
    expect($guest->can('view', $placement))->toBeTrue()
        ->and($guest->can('update', $placement))->toBeFalse();
});

it('refuses somebody from another workspace entirely', function (): void {
    [$placement] = cardSeenBy();
    $stranger = memberOf(Workspace::factory()->create());

    expect($stranger->can('view', $placement))->toBeFalse()
        ->and($stranger->can('update', $placement))->toBeFalse()
        ->and($stranger->can('delete', $placement))->toBeFalse();
});

it('answers attaching on the project, where there is something to judge', function (): void {
    [, $editor, $project] = cardSeenBy(ProjectAccessLevel::Editor);
    [, $viewer, $viewable] = cardSeenBy(ProjectAccessLevel::Viewer);

    expect($editor->can('placeTask', $project))->toBeTrue()
        ->and($viewer->can('placeTask', $viewable))->toBeFalse();
});
