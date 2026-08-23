<?php

declare(strict_types=1);

use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Actions\RenameSection;
use App\Domain\Section\Data\UpdateSectionData;
use App\Domain\Section\Events\SectionUpdated;
use App\Domain\Section\Exceptions\SectionException;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Support\Facades\Event;

function renameSection(Section $section, User $actor, UpdateSectionData $data): Section
{
    return app(RenameSection::class)->handle($section, $actor, $data);
}

it('renames a section and leaves its position alone', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'Backlog');
    $position = $section->position;

    renameSection($section, $actor, new UpdateSectionData(name: 'Up next'));

    expect($section->fresh()?->name)->toBe('Up next')
        ->and($section->fresh()?->position)->toBe($position);
});

it('sets and clears the colour', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor);

    renameSection($section, $actor, new UpdateSectionData(name: $section->name, color: ProjectColor::Amber));
    expect($section->fresh()?->color)->toBe(ProjectColor::Amber);

    // Null clears a nullable column rather than meaning "unchanged" — the rule Phase 040's
    // review settled after the same bug made project fields write-once.
    renameSection($section->refresh(), $actor, new UpdateSectionData(name: $section->name));
    expect($section->fresh()?->color)->toBeNull();
});

it('announces only what changed, and stays quiet on a no-op', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'Backlog');
    Event::fake();

    renameSection($section, $actor, new UpdateSectionData(name: 'Backlog'));
    Event::assertNotDispatched(SectionUpdated::class);

    renameSection($section, $actor, new UpdateSectionData(name: 'Later'));
    Event::assertDispatched(SectionUpdated::class, fn (SectionUpdated $event): bool => $event->changed === ['name']);
});

it('refuses an actor who may not shape the project', function (ProjectAccessLevel $access, WorkspaceRole $role): void {
    [$project, $owner] = projectEditableBy();
    $section = addSection($project, $owner, 'Backlog');

    [$otherProject, $actor] = projectEditableBy($access, $role);
    expect($otherProject->id)->not->toBe($project->id);

    expect(fn (): Section => renameSection($section, $actor, new UpdateSectionData(name: 'Renamed')))
        ->toThrow(SectionException::class);

    expect($section->fresh()?->name)->toBe('Backlog');
})->with([
    'commenter elsewhere' => [ProjectAccessLevel::Commenter, WorkspaceRole::Member],
    'viewer elsewhere' => [ProjectAccessLevel::Viewer, WorkspaceRole::Member],
    'editor in another workspace' => [ProjectAccessLevel::Editor, WorkspaceRole::Owner],
]);

it('refuses a viewer of the same project', function (): void {
    [$project, $editor] = projectEditableBy();
    $section = addSection($project, $editor, 'Backlog');
    $viewer = memberOf($project->workspace, WorkspaceRole::Member);
    ProjectMembership::factory()
        ->in($project)->forUser($viewer)->withAccess(ProjectAccessLevel::Viewer)->create();

    expect(fn (): Section => renameSection($section, $viewer, new UpdateSectionData(name: 'Renamed')))
        ->toThrow(SectionException::class, 'permission to change the sections');
});
