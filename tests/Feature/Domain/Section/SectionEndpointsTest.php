<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;

it('requires authentication', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'Backlog');

    $this->post(route('sections.store', $project), ['name' => 'New'])->assertRedirect(route('login'));
    $this->put(route('sections.update', $section), ['name' => 'New'])->assertRedirect(route('login'));
});

it('adds a section to a project', function (): void {
    [$project, $actor] = projectEditableBy();

    $this->actingAs($actor)
        ->from(route('projects.edit', $project))
        ->post(route('sections.store', $project), ['name' => 'Backlog', 'color' => ProjectColor::Teal->value])
        ->assertRedirect(route('projects.edit', $project));

    expect($project->sections()->pluck('name')->all())->toBe(['Backlog'])
        ->and($project->sections()->first()?->color?->paletteColor())->toBe(ProjectColor::Teal);
});

it('renames a section', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'Backlog');

    $this->actingAs($actor)->put(route('sections.update', $section), ['name' => 'Up next'])->assertRedirect();

    expect($section->fresh()?->name)->toBe('Up next');
});

/*
 * The contract every caller of this endpoint has to know: it replaces the row's two writable
 * columns rather than patching one of them. Asserted here because the list and the board both
 * rename a section from its own header, and a request carrying only the new name silently blanks
 * a colour somebody chose.
 */
it('replaces the colour rather than patching it', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'Backlog');
    $section->update(['color' => ProjectColor::Violet]);

    $this->actingAs($actor)
        ->put(route('sections.update', $section), ['name' => 'Up next', 'color' => ProjectColor::Violet->value])
        ->assertRedirect();

    expect($section->fresh()?->color?->paletteColor())->toBe(ProjectColor::Violet);

    $this->actingAs($actor)->put(route('sections.update', $section), ['name' => 'Up next'])->assertRedirect();

    expect($section->fresh()?->color)->toBeNull();
});

it('moves a section behind another and to the front', function (): void {
    [$project, $actor] = projectEditableBy();
    $a = addSection($project, $actor, 'A');
    $b = addSection($project, $actor, 'B');

    $this->actingAs($actor)->put(route('sections.move', $a), ['after' => $b->id])->assertRedirect();
    expect($project->sections()->pluck('name')->all())->toBe(['B', 'A']);

    $this->actingAs($actor)->put(route('sections.move', $a), [])->assertRedirect();
    expect($project->sections()->pluck('name')->all())->toBe(['A', 'B']);
});

it('deletes a section', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'Backlog');

    $this->actingAs($actor)->delete(route('sections.destroy', $section))->assertRedirect();

    expect(Section::query()->whereKey($section->id)->exists())->toBeFalse();
});

it('hides a section of another workspace behind a 404', function (string $method, string $name): void {
    [, $actor] = projectEditableBy();
    $theirProject = Project::factory()->create();
    $theirs = Section::factory()->in($theirProject)->create();

    $this->actingAs($actor)
        ->call($method, route($name, $theirs), ['name' => 'Renamed'])
        ->assertNotFound();

    expect($theirs->fresh()?->name)->not->toBe('Renamed');
})->with([
    'rename' => ['PUT', 'sections.update'],
    'move' => ['PUT', 'sections.move'],
    'delete' => ['DELETE', 'sections.destroy'],
]);

it('hides a section of a private project the actor was never given', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $project = Project::factory()->in($workspace)->private()->create();
    $section = Section::factory()->in($project)->create();

    $this->actingAs($actor)->put(route('sections.update', $section), ['name' => 'Renamed'])->assertNotFound();
});

it('refuses a viewer of the project', function (): void {
    [$project, $editor] = projectEditableBy();
    $section = addSection($project, $editor, 'Backlog');
    $viewer = memberOf($project->workspace, WorkspaceRole::Member);
    ProjectMembership::factory()->in($project)->forUser($viewer)->withAccess(ProjectAccessLevel::Viewer)->create();

    $this->actingAs($viewer)->put(route('sections.update', $section), ['name' => 'Renamed'])->assertForbidden();
    $this->actingAs($viewer)->delete(route('sections.destroy', $section))->assertForbidden();

    expect($section->fresh()?->name)->toBe('Backlog');
});

it('refuses an anchor from another project with a validation error, not a 500', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'A');
    [$otherProject, $otherActor] = projectEditableBy();
    $foreign = addSection($otherProject, $otherActor, 'Theirs');

    $this->actingAs($actor)
        ->from(route('projects.edit', $project))
        ->put(route('sections.move', $section), ['after' => $foreign->id])
        ->assertSessionHasErrors('after');
});

it('rejects a malformed section id at routing', function (): void {
    [, $actor] = projectEditableBy();

    $this->actingAs($actor)->put('sections/not-a-uuid', ['name' => 'Renamed'])->assertNotFound();
});
