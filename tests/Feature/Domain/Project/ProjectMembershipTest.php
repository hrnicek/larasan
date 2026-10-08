<?php

declare(strict_types=1);

use App\Domain\Project\Actions\GrantProjectAccess;
use App\Domain\Project\Actions\RevokeProjectAccess;
use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{Workspace, Project, User, User}
 */
function projectWithOwner(): array
{
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    $other = memberOf($workspace, WorkspaceRole::Member);

    $project = Project::factory()->in($workspace)->create();
    ProjectMembership::query()->create([
        'project_id' => $project->id,
        'user_id' => $owner->id,
        'access_level' => ProjectAccessLevel::Owner,
    ]);

    return [$workspace, $project, $owner, $other];
}

it('grants access at the level asked for', function (): void {
    [, $project, $owner, $other] = projectWithOwner();

    $this->actingAs($owner)
        ->post(route('projects.members.store', $project), [
            'user' => $other->id,
            'access_level' => ProjectAccessLevel::Editor->value,
        ])
        ->assertRedirect();

    expect($project->memberships()->where('user_id', $other->id)->sole()->access_level)
        ->toBe(ProjectAccessLevel::Editor);
});

it('treats granting twice as changing the level, not as a second row', function (): void {
    [, $project, $owner, $other] = projectWithOwner();

    foreach ([ProjectAccessLevel::Viewer, ProjectAccessLevel::Editor] as $level) {
        $this->actingAs($owner)->post(route('projects.members.store', $project), [
            'user' => $other->id,
            'access_level' => $level->value,
        ])->assertRedirect();
    }

    expect($project->memberships()->where('user_id', $other->id)->count())->toBe(1)
        ->and($project->memberships()->where('user_id', $other->id)->sole()->access_level)
        ->toBe(ProjectAccessLevel::Editor);
});

it('changes an access level', function (): void {
    [, $project, $owner, $other] = projectWithOwner();
    $membership = app(GrantProjectAccess::class)->handle($project, $owner, $other, ProjectAccessLevel::Viewer);

    $this->actingAs($owner)
        ->put(route('projects.members.update', [$project, $membership]), [
            'access_level' => ProjectAccessLevel::Commenter->value,
        ])
        ->assertRedirect();

    expect($membership->fresh()?->access_level)->toBe(ProjectAccessLevel::Commenter);
});

it('revokes access and keeps what the person wrote', function (): void {
    [, $project, $owner, $other] = projectWithOwner();
    $membership = app(GrantProjectAccess::class)->handle($project, $owner, $other, ProjectAccessLevel::Editor);

    $this->actingAs($owner)
        ->delete(route('projects.members.destroy', [$project, $membership]))
        ->assertRedirect();

    expect($project->memberships()->where('user_id', $other->id)->count())->toBe(0)
        ->and(User::query()->whereKey($other->id)->exists())->toBeTrue();
});

it('refuses somebody who is not in the workspace', function (): void {
    [, $project, $owner] = projectWithOwner();
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    $this->actingAs($owner)
        ->from(route('projects.edit', $project))
        ->post(route('projects.members.store', $project), [
            'user' => $outsider->id,
            'access_level' => ProjectAccessLevel::Editor->value,
        ])
        ->assertSessionHasErrors('user');

    expect(fn () => app(GrantProjectAccess::class)->handle($project, $owner, $outsider, ProjectAccessLevel::Editor))
        ->toThrow(ProjectException::class);

    expect($project->memberships()->count())->toBe(1);
});

it('will not let the last owner be demoted', function (): void {
    [, $project, $owner] = projectWithOwner();
    $membership = $project->memberships()->sole();

    // Managing requires an explicit owner row, which not even the workspace owner can bypass.
    $this->actingAs($owner)
        ->from(route('projects.edit', $project))
        ->put(route('projects.members.update', [$project, $membership]), [
            'access_level' => ProjectAccessLevel::Editor->value,
        ])
        ->assertSessionHasErrors();

    expect($membership->fresh()?->access_level)->toBe(ProjectAccessLevel::Owner);
});

it('will not let the last owner be removed', function (): void {
    [, $project, $owner] = projectWithOwner();
    $membership = $project->memberships()->sole();

    $this->actingAs($owner)
        ->from(route('projects.edit', $project))
        ->delete(route('projects.members.destroy', [$project, $membership]))
        ->assertSessionHasErrors();

    expect($project->memberships()->count())->toBe(1);
});

it('lets an owner step down once there is a second one', function (): void {
    [, $project, $owner, $other] = projectWithOwner();
    app(GrantProjectAccess::class)->handle($project, $owner, $other, ProjectAccessLevel::Owner);
    $membership = $project->memberships()->where('user_id', $owner->id)->sole();

    $this->actingAs($owner)
        ->put(route('projects.members.update', [$project, $membership]), [
            'access_level' => ProjectAccessLevel::Editor->value,
        ])
        ->assertRedirect();

    expect($membership->fresh()?->access_level)->toBe(ProjectAccessLevel::Editor);
});

it('refuses an editor, and a workspace owner without an owner row', function (): void {
    [$workspace, $project, , $other] = projectWithOwner();
    $editor = memberOf($workspace, WorkspaceRole::Member);
    app(GrantProjectAccess::class)->handle($project, $project->memberships()->sole()->user, $editor, ProjectAccessLevel::Editor);

    $this->actingAs($editor)
        ->post(route('projects.members.store', $project), [
            'user' => $other->id,
            'access_level' => ProjectAccessLevel::Viewer->value,
        ])
        ->assertForbidden();

    $admin = memberOf($workspace, WorkspaceRole::Admin);

    $this->actingAs($admin)
        ->post(route('projects.members.store', $project), [
            'user' => $other->id,
            'access_level' => ProjectAccessLevel::Viewer->value,
        ])
        ->assertForbidden();

    expect($project->memberships()->count())->toBe(2);
});

it('answers a membership from another project with a 404', function (): void {
    [$workspace, $project, $owner, $other] = projectWithOwner();
    $elsewhere = Project::factory()->in($workspace)->create();
    ProjectMembership::query()->create([
        'project_id' => $elsewhere->id,
        'user_id' => $owner->id,
        'access_level' => ProjectAccessLevel::Owner,
    ]);
    $theirs = $elsewhere->memberships()->sole();

    $this->actingAs($owner)
        ->put(route('projects.members.update', [$project, $theirs]), [
            'access_level' => ProjectAccessLevel::Viewer->value,
        ])
        ->assertNotFound();

    $this->actingAs($owner)
        ->delete(route('projects.members.destroy', [$project, $theirs]))
        ->assertNotFound();

    expect($theirs->fresh()?->access_level)->toBe(ProjectAccessLevel::Owner)
        ->and($other->id)->not->toBeNull();
});

it('answers a project in another workspace with a 404', function (): void {
    [, , $owner] = projectWithOwner();
    [$elsewhere, $stranger] = workspaceWith(WorkspaceRole::Owner);
    $theirs = Project::factory()->in($elsewhere)->create();

    $this->actingAs($owner)
        ->post(route('projects.members.store', $theirs), [
            'user' => $stranger->id,
            'access_level' => ProjectAccessLevel::Editor->value,
        ])
        ->assertNotFound();
});

it('refuses an access level that is not one', function (): void {
    [, $project, $owner, $other] = projectWithOwner();

    $this->actingAs($owner)
        ->from(route('projects.edit', $project))
        ->post(route('projects.members.store', $project), [
            'user' => $other->id,
            'access_level' => 'superuser',
        ])
        ->assertSessionHasErrors('access_level');

    expect($project->memberships()->count())->toBe(1);
});

it('refuses a membership id that is not a uuid', function (): void {
    [, $project, $owner] = projectWithOwner();

    $this->actingAs($owner)
        ->delete("/projects/{$project->id}/members/not-a-uuid")
        ->assertNotFound();
});

it('holds the last-owner rule for a caller without a request', function (): void {
    [, $project, $owner] = projectWithOwner();

    expect(fn () => app(RevokeProjectAccess::class)->handle($project, $owner, $project->memberships()->sole()))
        ->toThrow(ProjectException::class, 'A project needs at least one owner.');
});

it("sends the project's faces with the screen", function (): void {
    [, $project, $owner, $other] = projectWithOwner();
    app(GrantProjectAccess::class)->handle($project, $owner, $other, ProjectAccessLevel::Editor);

    $this->actingAs($owner)
        ->get(route('projects.show', $project))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('project.members', 2)
            ->where('project.memberCount', 2)
            ->missing('share'),
        );
});

it('draws five faces and counts the rest', function (): void {
    [$workspace, $project, $owner] = projectWithOwner();

    foreach (range(1, 7) as $ignored) {
        app(GrantProjectAccess::class)->handle(
            $project,
            $owner,
            memberOf($workspace, WorkspaceRole::Member),
            ProjectAccessLevel::Viewer,
        );
    }

    $this->actingAs($owner)
        ->get(route('projects.show', $project))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('project.members', 5)
            ->where('project.memberCount', 8),
        );
});

it('answers the share dialog when it asks', function (): void {
    [$workspace, $project, $owner, $other] = projectWithOwner();
    app(GrantProjectAccess::class)->handle($project, $owner, $other, ProjectAccessLevel::Editor);
    $candidate = memberOf($workspace, WorkspaceRole::Member);

    $owner->update(['name' => 'Aaron Owner']);
    $other->update(['name' => 'Bella Editor']);
    $candidate->update(['name' => 'Cara Candidate']);

    $this->actingAs($owner)
        ->withoutMiddleware(HandleInertiaRequests::class)
        ->get(route('projects.show', $project), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'projects/Show',
            'X-Inertia-Partial-Data' => 'share',
        ])
        ->assertOk()
        ->assertJsonPath('props.share.canManage', true)
        ->assertJsonPath('props.share.link', route('projects.show', $project))
        ->assertJsonCount(2, 'props.share.members')
        ->assertJsonPath('props.share.members.0.id', $owner->id)
        ->assertJsonPath('props.share.members.0.accessLevel', ProjectAccessLevel::Owner->value)
        ->assertJsonPath('props.share.members.0.isLastOwner', true)
        ->assertJsonPath('props.share.members.1.id', $other->id)
        ->assertJsonPath('props.share.members.1.isLastOwner', false)
        ->assertJsonCount(1, 'props.share.candidates')
        ->assertJsonPath('props.share.candidates.0.id', $candidate->id);
});

it('sends no candidates to somebody who may not manage members', function (): void {
    [$workspace, $project, $owner] = projectWithOwner();
    $viewer = memberOf($workspace, WorkspaceRole::Member);
    app(GrantProjectAccess::class)->handle($project, $owner, $viewer, ProjectAccessLevel::Viewer);

    $this->actingAs($viewer)
        ->withoutMiddleware(HandleInertiaRequests::class)
        ->get(route('projects.show', $project), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'projects/Show',
            'X-Inertia-Partial-Data' => 'share',
        ])
        ->assertOk()
        ->assertJsonPath('props.share.canManage', false)
        ->assertJsonCount(2, 'props.share.members')
        ->assertJsonCount(0, 'props.share.candidates');
});

it('reads the owners from the database rather than trusting the membership it was handed', function (): void {
    [, $project, $owner, $other] = projectWithOwner();
    $loadedWhileEditor = app(GrantProjectAccess::class)->handle($project, $owner, $other, ProjectAccessLevel::Editor);

    app(GrantProjectAccess::class)->handle($project, $owner, $other, ProjectAccessLevel::Owner);
    app(GrantProjectAccess::class)->handle($project, $other, $owner, ProjectAccessLevel::Editor);

    expect(fn () => app(RevokeProjectAccess::class)->handle($project, $other, $loadedWhileEditor))
        ->toThrow(ProjectException::class, 'A project needs at least one owner.');

    expect($project->memberships()->where('access_level', ProjectAccessLevel::Owner->value)->pluck('user_id')->all())
        ->toBe([$other->id]);
});

it('locks the owner rows before it removes or demotes an owner', function (string $change): void {
    [, $project, $owner, $other] = projectWithOwner();
    app(GrantProjectAccess::class)->handle($project, $owner, $other, ProjectAccessLevel::Owner);
    $membership = $project->memberships()->where('user_id', $other->id)->sole();

    DB::flushQueryLog();
    DB::enableQueryLog();

    match ($change) {
        'revoke' => app(RevokeProjectAccess::class)->handle($project, $owner, $membership),
        'demote' => app(GrantProjectAccess::class)->handle($project, $owner, $other, ProjectAccessLevel::Editor),
        default => throw new InvalidArgumentException("Unknown change [{$change}]."),
    };

    DB::disableQueryLog();

    $statements = array_column(DB::getQueryLog(), 'query');

    $ownerLock = indexOfStatement($statements, fn (string $sql): bool => str_contains($sql, 'from "project_memberships"')
        && str_contains($sql, '"access_level" = ?')
        && str_ends_with($sql, 'for update'));

    $write = indexOfStatement($statements, fn (string $sql): bool => str_starts_with($sql, 'delete from "project_memberships"')
        || str_starts_with($sql, 'update "project_memberships"'));

    expect($ownerLock)->toBeLessThan($write);
})->with(['revoke', 'demote']);
