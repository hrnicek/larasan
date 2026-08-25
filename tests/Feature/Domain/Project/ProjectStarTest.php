<?php

declare(strict_types=1);

use App\Domain\Project\Actions\StarProject;
use App\Domain\Project\Actions\UnstarProject;
use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectStar;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

it('stars a project from wherever the menu was opened', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    $this->actingAs($actor)
        ->from(route('projects.show', $project))
        ->post(route('projects.star', $project))
        ->assertRedirect(route('projects.show', $project));

    expect(ProjectStar::query()->where('project_id', $project->id)->where('user_id', $actor->id)->exists())
        ->toBeTrue();
});

it('treats starring twice as starring once', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    $this->actingAs($actor)->post(route('projects.star', $project));
    $this->actingAs($actor)->post(route('projects.star', $project));

    expect(ProjectStar::query()->where('project_id', $project->id)->count())->toBe(1);
});

it('unstars, and says nothing when there was no star', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    ProjectStar::factory()->starring($project, $actor)->create();

    $this->actingAs($actor)->delete(route('projects.unstar', $project));

    expect(ProjectStar::query()->where('project_id', $project->id)->exists())->toBeFalse();

    $this->actingAs($actor)->delete(route('projects.unstar', $project))->assertRedirect();

    expect(ProjectStar::query()->where('project_id', $project->id)->exists())->toBeFalse();
});

/*
 * A star is a shortcut rather than a change to the project, so the question it asks is `view`.
 * A reader who may not rename the project may still keep it at the top of their own sidebar.
 */
it('lets a reader star a project they may only look at', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Viewer);

    $this->actingAs($actor)->post(route('projects.star', $project))->assertRedirect();

    expect(ProjectStar::query()->where('project_id', $project->id)->exists())->toBeTrue();
});

/*
 * 404 rather than 403: `{project}` binds among the projects the actor may see, so a private one
 * they were never given is indistinguishable from one that does not exist. The Action refuses it
 * a second time for callers that arrive without a route.
 */
it('refuses a project the actor cannot open', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $private = Project::factory()->in($workspace)->private()->create();

    $this->actingAs($actor)->post(route('projects.star', $private))->assertNotFound();

    expect(ProjectStar::query()->where('project_id', $private->id)->exists())->toBeFalse();
});

it('refuses a project in another workspace', function (): void {
    [, $actor] = projectFor(WorkspaceRole::Admin, ProjectAccessLevel::Owner);
    $elsewhere = Project::factory()->in(Workspace::factory()->create())->create();

    $this->actingAs($actor)->post(route('projects.star', $elsewhere))->assertNotFound();

    expect(ProjectStar::query()->where('project_id', $elsewhere->id)->exists())->toBeFalse();
});

it('refuses every caller who cannot reach the project, not only the HTTP one', function (): void {
    [$project] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Admin);

    expect(fn (): ProjectStar => app(StarProject::class)->handle($project, $outsider))
        ->toThrow(ProjectException::class);
});

/*
 * The other direction has no reach check on purpose: somebody who has lost access must still be
 * able to clear the project out of their own sidebar.
 */
it('lets somebody unstar a project they can no longer open', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Viewer);
    ProjectStar::factory()->starring($project, $actor)->create();

    $project->memberships()->where('user_id', $actor->id)->delete();
    $project->forceFill(['visibility' => ProjectVisibility::Private->value])->save();

    expect($project->refresh()->isVisibleTo($actor))->toBeFalse();

    app(UnstarProject::class)->handle($project, $actor);

    expect(ProjectStar::query()->where('project_id', $project->id)->exists())->toBeFalse();
});

it('tells the sidebar which rows this actor starred', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $starred = Project::factory()->in($workspace)->create(['name' => 'A starred one']);
    Project::factory()->in($workspace)->create(['name' => 'B plain one']);
    ProjectStar::factory()->starring($starred, $actor)->create();

    $this->actingAs($actor)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('projects.0.id', $starred->id)
            ->where('projects.0.starred', true)
            ->where('projects.1.starred', false));
});

it('reads one person\'s star and not another\'s', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $somebodyElse = memberOf($workspace, WorkspaceRole::Member);
    $project = Project::factory()->in($workspace)->create();
    ProjectStar::factory()->starring($project, $somebodyElse)->create();

    $this->actingAs($actor)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('projects.0.starred', false));
});

/*
 * The shared list is capped at fifteen and ordered by name, so a starred project late in the
 * alphabet is exactly the one the cap would have cut off.
 */
it('keeps a starred project in the list the cap would have cut off', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    Project::factory()->in($workspace)->count(20)->sequence(
        fn (Sequence $sequence): array => ['name' => 'Project '.$sequence->index],
    )->create();
    $last = Project::factory()->in($workspace)->create(['name' => 'Zebra']);
    ProjectStar::factory()->starring($last, $actor)->create();

    $this->actingAs($actor)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('projects', 15)
            ->where('projects.0.id', $last->id)
            ->where('projects.0.starred', true));
});

it('still reads the sidebar list in one query', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $projects = Project::factory()->in($workspace)->count(3)->create();
    ProjectStar::factory()->starring($projects->first(), $actor)->create();

    DB::enableQueryLog();

    $this->actingAs($actor)->get(route('dashboard'))->assertOk();

    $projectQueries = array_filter(
        DB::getQueryLog(),
        fn (array $query): bool => str_contains((string) $query['query'], 'from "projects"'),
    );

    expect($projectQueries)->toHaveCount(1);
});

it('sends the project header this reader\'s own star', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Viewer);

    $this->actingAs($actor)
        ->get(route('projects.show', $project))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('project.starred', false));

    ProjectStar::factory()->starring($project, $actor)->create();

    $this->actingAs($actor)
        ->get(route('projects.show', $project))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('project.starred', true));
});

it('refuses a second star in the database itself', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    ProjectStar::factory()->starring($project, $actor)->create();

    expect(fn (): ProjectStar => ProjectStar::factory()->starring($project, $actor)->create())
        ->toThrow(QueryException::class);
});

it('takes the star with the project', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    ProjectStar::factory()->starring($project, $actor)->create();

    $project->forceDelete();

    expect(ProjectStar::query()->where('project_id', $project->id)->exists())->toBeFalse();
});
