<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Actions\CreateSection;
use App\Domain\Section\Data\CreateSectionData;
use App\Domain\Section\Events\SectionCreated;
use App\Domain\Section\Exceptions\SectionException;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Shared\ValueObjects\AccentColor;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Event;

it('appends a section to the end of the project', function (): void {
    [$project, $actor] = projectEditableBy();

    $first = addSection($project, $actor, 'Backlog');
    $second = addSection($project, $actor, 'In progress');

    expect($first->position)->toBe(SparsePosition::GAP)
        ->and($second->position)->toBe(2 * SparsePosition::GAP)
        ->and($project->sections()->pluck('name')->all())->toBe(['Backlog', 'In progress']);
});

it('keeps appending past the second section', function (): void {
    [$project, $actor] = projectEditableBy();

    $names = ['Backlog', 'In progress', 'Review', 'Done'];

    foreach ($names as $name) {
        addSection($project, $actor, $name);
    }

    expect($project->sections()->pluck('name')->all())->toBe($names)
        ->and($project->sections()->pluck('position')->all())
        ->toBe(SparsePosition::spread(count($names)));
});

it('announces the section it created', function (): void {
    Event::fake();
    [$project, $actor] = projectEditableBy();

    $section = addSection($project, $actor);

    Event::assertDispatched(SectionCreated::class, fn (SectionCreated $event): bool => $event->sectionId === $section->id
        && $event->projectId === $project->id
        && $event->createdById === $actor->id);
});

it('starts a column slate rather than colourless', function (): void {
    [$project, $actor] = projectEditableBy();

    $section = app(CreateSection::class)->handle($project, $actor, new CreateSectionData(name: 'Blocked'));

    expect($section->fresh()?->color?->paletteColor())->toBe(ProjectColor::Slate);
});

it('stores a palette colour', function (): void {
    [$project, $actor] = projectEditableBy();

    $section = app(CreateSection::class)->handle(
        $project,
        $actor,
        new CreateSectionData(name: 'Blocked', color: AccentColor::palette(ProjectColor::Red)),
    );

    expect($section->fresh()?->color?->paletteColor())->toBe(ProjectColor::Red);
});

it('takes the name as content and reads nothing into it', function (): void {
    [$project, $actor] = projectEditableBy();

    $section = addSection($project, $actor, 'Done');

    expect($section->name)->toBe('Done')
        ->and($section->getAttributes())->not->toHaveKey('completes_tasks');
});

it('refuses an actor without both halves of the answer', function (ProjectAccessLevel $access, WorkspaceRole $role): void {
    [$project, $actor] = projectEditableBy($access, $role);

    expect(fn (): Section => addSection($project, $actor))
        ->toThrow(SectionException::class, 'permission to change the sections');

    expect($project->sections()->count())->toBe(0);
})->with([
    'commenter in the project' => [ProjectAccessLevel::Commenter, WorkspaceRole::Member],
    'viewer in the project' => [ProjectAccessLevel::Viewer, WorkspaceRole::Member],
    'project editor but a workspace guest' => [ProjectAccessLevel::Editor, WorkspaceRole::Guest],
]);

it('refuses a workspace admin with no access to the project', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $project = Project::factory()->in($workspace)->create();

    expect(fn (): Section => addSection($project, $admin))->toThrow(SectionException::class);
});

it('refuses someone who cannot see the project at all', function (): void {
    $workspace = Workspace::factory()->create();
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);
    $project = Project::factory()->in($workspace)->private()->create();

    expect(fn (): Section => addSection($project, $outsider))->toThrow(SectionException::class);
});

it('keeps sections of different projects independent', function (): void {
    [$mine, $actor] = projectEditableBy();
    $theirs = Project::factory()->in($mine->workspace)->create(['visibility' => ProjectVisibility::Workspace]);
    ProjectMembership::factory()->in($theirs)->forUser($actor)->withAccess(ProjectAccessLevel::Editor)->create();

    addSection($mine, $actor, 'Backlog');
    $other = addSection($theirs, $actor, 'Backlog');

    expect($other->position)->toBe(SparsePosition::GAP)
        ->and(Section::query()->count())->toBe(2);
});
