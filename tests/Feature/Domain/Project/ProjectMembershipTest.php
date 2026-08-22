<?php

declare(strict_types=1);

use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\LazyLoadingViolationException;

it('casts the access level and relates both ways', function (): void {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $membership = ProjectMembership::factory()->in($project)->forUser($user)
        ->withAccess(ProjectAccessLevel::Commenter)->create();

    expect($membership->access_level)->toBe(ProjectAccessLevel::Commenter)
        ->and($membership->project->is($project))->toBeTrue()
        ->and($membership->user->is($user))->toBeTrue()
        ->and($membership->getRawOriginal('access_level'))->toBe('commenter');
});

it('answers what the level permits without anyone comparing strings', function (): void {
    $editor = ProjectMembership::factory()->withAccess(ProjectAccessLevel::Editor)->create();
    $viewer = ProjectMembership::factory()->withAccess(ProjectAccessLevel::Viewer)->create();

    expect($editor->access_level->canEdit())->toBeTrue()
        ->and($editor->access_level->canManageProject())->toBeFalse()
        ->and($viewer->access_level->canComment())->toBeFalse();
});

it('finds the membership for a person, and null for everyone else', function (): void {
    $project = Project::factory()->create();
    $member = User::factory()->create();
    ProjectMembership::factory()->in($project)->forUser($member)->create();

    expect($project->memberFor($member)?->access_level)->toBe(ProjectAccessLevel::Editor)
        ->and($project->memberFor(User::factory()->create()))->toBeNull();
});

it('lists a workspace its projects and a user their explicit projects', function (): void {
    $workspace = Workspace::factory()->create();
    $mine = Project::factory()->in($workspace)->create();
    Project::factory()->in($workspace)->create();
    Project::factory()->create();
    $user = User::factory()->create();

    ProjectMembership::factory()->in($mine)->forUser($user)->create();

    expect($workspace->projects()->count())->toBe(2)
        ->and($user->projects()->pluck('projects.id')->all())->toBe([$mine->id]);
});

it('carries the access level on the pivot, so a listing needs no second query', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    ProjectMembership::factory()->in($project)->forUser($user)
        ->withAccess(ProjectAccessLevel::Owner)->create();

    $pivot = $user->projects()->first()?->getRelationValue('pivot');

    expect($pivot?->getAttribute('access_level'))->toBe('owner');
});

it('resolves memberships without an n+1 when eager loaded', function (): void {
    $projects = Project::factory()->count(2)->create();

    foreach ($projects as $project) {
        ProjectMembership::factory()->in($project)->create();
    }

    $eager = Project::query()->with('memberships.user')->get();

    expect($eager->pluck('memberships')->flatten()->pluck('user')->filter())->toHaveCount(2);

    $lazy = Project::query()->get();

    expect(fn (): mixed => $lazy->first()->memberships)
        ->toThrow(LazyLoadingViolationException::class);
});

it('refuses a project membership for somebody who is not in the workspace', function (): void {
    $project = Project::factory()->create();
    $stranger = User::factory()->create();

    /*
     * TASK-040-021's invariant. Access to a project is access inside a workspace, so a grant
     * to somebody who is not in that workspace means nothing and reads as if it means
     * something — which is worse than not existing.
     */
    expect(fn (): ProjectMembership => ProjectMembership::query()->create([
        'project_id' => $project->id,
        'user_id' => $stranger->id,
        'access_level' => ProjectAccessLevel::Editor,
    ]))->toThrow(ProjectException::class, 'not an active member');

    expect($project->memberships()->count())->toBe(0);
});

it('refuses one for somebody whose workspace membership has not been accepted', function (): void {
    $workspace = Workspace::factory()->create();
    $invited = memberOf($workspace, WorkspaceRole::Member, WorkspaceMembershipStatus::Invited);
    $project = Project::factory()->in($workspace)->create();

    // An invitation is not a membership: until it is accepted there is nobody to grant to.
    expect(fn (): ProjectMembership => ProjectMembership::query()->create([
        'project_id' => $project->id,
        'user_id' => $invited->id,
        'access_level' => ProjectAccessLevel::Viewer,
    ]))->toThrow(ProjectException::class);
});

it('keeps a grant that was made while the person was still a member', function (): void {
    $workspace = Workspace::factory()->create();
    $member = memberOf($workspace, WorkspaceRole::Member);
    $project = Project::factory()->in($workspace)->create();
    $membership = ProjectMembership::factory()->in($project)->forUser($member)->create();

    $workspace->membershipFor($member)?->forceFill(['status' => WorkspaceMembershipStatus::Revoked])->save();

    // The row survives — what it grants does not, which is `isVisibleTo()`'s answer rather
    // than the row's existence.
    expect($membership->fresh())->not->toBeNull()
        ->and($project->fresh()?->isVisibleTo($member))->toBeFalse();
});
