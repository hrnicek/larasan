<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Actions\MoveSection;
use App\Domain\Section\Events\SectionMoved;
use App\Domain\Section\Exceptions\SectionException;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Models\User;
use Illuminate\Support\Facades\Event;

function move(Section $section, User $actor, ?Section $after): Section
{
    return app(MoveSection::class)->handle($section, $actor, $after);
}

/**
 * @return list<string>
 */
function order(Project $project): array
{
    return array_values($project->sections()->get()->map(fn (Section $section): string => $section->name)->all());
}

it('moves a section to the front', function (): void {
    [$project, $actor] = projectEditableBy();
    addSection($project, $actor, 'A');
    addSection($project, $actor, 'B');
    $c = addSection($project, $actor, 'C');

    move($c, $actor, null);

    expect(order($project))->toBe(['C', 'A', 'B']);
});

it('moves a section between two others', function (): void {
    [$project, $actor] = projectEditableBy();
    $a = addSection($project, $actor, 'A');
    addSection($project, $actor, 'B');
    $c = addSection($project, $actor, 'C');

    move($c, $actor, $a);

    expect(order($project))->toBe(['A', 'C', 'B']);
});

it('moves a section to the end', function (): void {
    [$project, $actor] = projectEditableBy();
    $a = addSection($project, $actor, 'A');
    addSection($project, $actor, 'B');
    $c = addSection($project, $actor, 'C');

    move($a, $actor, $c);

    expect(order($project))->toBe(['B', 'C', 'A']);
});

it('writes one row for a move', function (): void {
    [$project, $actor] = projectEditableBy();
    $a = addSection($project, $actor, 'A');
    $b = addSection($project, $actor, 'B');
    $c = addSection($project, $actor, 'C');
    $untouched = [$a->position, $b->position];

    move($c, $actor, $a);

    expect($a->fresh()?->position)->toBe($untouched[0])
        ->and($b->fresh()?->position)->toBe($untouched[1]);
});

it('stays quiet when the section is already where it was asked to go', function (): void {
    [$project, $actor] = projectEditableBy();
    $a = addSection($project, $actor, 'A');
    $b = addSection($project, $actor, 'B');
    Event::fake();

    move($b, $actor, $a);

    Event::assertNotDispatched(SectionMoved::class);
    expect(order($project))->toBe(['A', 'B']);
});

it('announces the move and what it now follows', function (): void {
    [$project, $actor] = projectEditableBy();
    $a = addSection($project, $actor, 'A');
    $b = addSection($project, $actor, 'B');
    Event::fake();

    move($a, $actor, $b);

    Event::assertDispatched(SectionMoved::class, fn (SectionMoved $event): bool => $event->sectionId === $a->id
        && $event->afterSectionId === $b->id);
});

it('normalises when the neighbours have closed up, and still lands in the right place', function (): void {
    [$project, $actor] = projectEditableBy();
    $a = addSection($project, $actor, 'A');
    $b = addSection($project, $actor, 'B');
    $c = addSection($project, $actor, 'C');

    // A gap of one: there is no midpoint left between A and B.
    $a->forceFill(['position' => 1000])->save();
    $b->forceFill(['position' => 1001])->save();
    $c->forceFill(['position' => 5000])->save();

    move($c, $actor, $a);

    expect(order($project))->toBe(['A', 'C', 'B'])
        ->and($project->sections()->pluck('position')->all())->each->toBeGreaterThan(0);

    // Everything is spread again, so the next insertion has room.
    $positions = $project->sections()->pluck('position')->all();
    expect($positions[1] - $positions[0])->toBeGreaterThanOrEqual(SparsePosition::MINIMUM_GAP * 2);
});

it('keeps every position unique through a long shuffle', function (): void {
    [$project, $actor] = projectEditableBy();
    $sections = array_map(fn (string $name): Section => addSection($project, $actor, $name), ['A', 'B', 'C', 'D']);

    foreach (range(1, 12) as $round) {
        $moving = $sections[$round % 4];
        $anchor = $round % 3 === 0 ? null : $sections[($round + 1) % 4];

        if ($anchor?->is($moving) === true) {
            $anchor = null;
        }

        move($moving->refresh(), $actor, $anchor?->refresh());
    }

    $positions = $project->sections()->pluck('position')->all();

    expect($positions)->toHaveCount(4)
        ->and(array_unique($positions))->toHaveCount(4)
        ->and($positions)->toBe(array_values(collect($positions)->sort()->all()));
});

it('refuses an anchor from another project', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'A');
    [$otherProject, $otherActor] = projectEditableBy();
    $foreign = addSection($otherProject, $otherActor, 'Theirs');

    expect(fn (): Section => move($section, $actor, $foreign))
        ->toThrow(SectionException::class, 'not in this project');
});

it('refuses to place a section after itself', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'A');

    expect(fn (): Section => move($section, $actor, $section))
        ->toThrow(SectionException::class, 'after itself');
});

it('refuses an actor who may not shape the project', function (): void {
    [$project, $editor] = projectEditableBy();
    $a = addSection($project, $editor, 'A');
    $b = addSection($project, $editor, 'B');
    $viewer = memberOf($project->workspace, WorkspaceRole::Member);
    ProjectMembership::factory()
        ->in($project)->forUser($viewer)->withAccess(ProjectAccessLevel::Viewer)->create();

    expect(fn (): Section => move($b, $viewer, null))->toThrow(SectionException::class);

    expect(order($project))->toBe(['A', 'B']);
});
