<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Actions\DeleteSection;
use App\Domain\Section\Events\SectionDeleted;
use App\Domain\Section\Exceptions\SectionException;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Event;

it('deletes the section outright', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'Backlog');

    app(DeleteSection::class)->handle($section, $actor);

    // No soft deletes on sections: gone means gone, and no query has to remember a filter.
    expect(Section::query()->whereKey($section->id)->exists())->toBeFalse()
        ->and($project->sections()->count())->toBe(0);
});

it('leaves the other columns where they were', function (): void {
    [$project, $actor] = projectEditableBy();
    $a = addSection($project, $actor, 'A');
    $b = addSection($project, $actor, 'B');
    $c = addSection($project, $actor, 'C');
    $positions = [$a->position, $c->position];

    app(DeleteSection::class)->handle($b, $actor);

    expect($a->fresh()?->position)->toBe($positions[0])
        ->and($c->fresh()?->position)->toBe($positions[1])
        ->and($project->sections()->count())->toBe(2);
});

it('allows deleting the last remaining column', function (): void {
    [$project, $actor] = projectEditableBy();
    $only = addSection($project, $actor, 'Everything');

    app(DeleteSection::class)->handle($only, $actor);

    // A project with no columns is a valid project: the list view groups what has no
    // section, and the board offers to create one.
    expect($project->sections()->count())->toBe(0);
});

it('announces the deletion', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor);
    Event::fake();

    app(DeleteSection::class)->handle($section, $actor);

    Event::assertDispatched(SectionDeleted::class, fn (SectionDeleted $event): bool => $event->sectionId === $section->id
        && $event->projectId === $project->id
        && $event->deletedById === $actor->id);
});

it('refuses an actor who may not shape the project', function (): void {
    [$project, $editor] = projectEditableBy();
    $section = addSection($project, $editor, 'Backlog');
    $viewer = memberOf($project->workspace, WorkspaceRole::Member);
    ProjectMembership::factory()->in($project)->forUser($viewer)->withAccess(ProjectAccessLevel::Viewer)->create();

    expect(fn () => app(DeleteSection::class)->handle($section, $viewer))
        ->toThrow(SectionException::class, 'permission to change the sections');

    expect(Section::query()->whereKey($section->id)->exists())->toBeTrue();
});

it('refuses someone outside the workspace entirely', function (): void {
    [$project, $editor] = projectEditableBy();
    $section = addSection($project, $editor, 'Backlog');
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    expect(fn () => app(DeleteSection::class)->handle($section, $outsider))
        ->toThrow(SectionException::class);

    expect(Section::query()->whereKey($section->id)->exists())->toBeTrue();
});

it('moves the cards in the column to the ungrouped bucket, in the order they were in', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'Doing');

    $cards = collect(['A', 'B', 'C'])->map(fn (string $title, int $index): TaskProjectMembership => TaskProjectMembership::factory()
        ->placing(Task::factory()->in($project->workspace)->create(['title' => $title]), $project)
        ->inSection($section)
        ->at(($index + 1) * SparsePosition::GAP)
        ->create());

    app(DeleteSection::class)->handle($section, $actor);

    // ADR-0004: deleting a column deletes the column, not the work that was in it.
    $ungrouped = $project->placements()->whereNull('section_id')->orderBy('position')->with('task')->get();

    expect($ungrouped->pluck('task.title')->all())->toBe(['A', 'B', 'C'])
        ->and($ungrouped->pluck('id')->all())->toBe($cards->pluck('id')->all())
        ->and(Task::query()->count())->toBe(3);
});

it('does not collide with cards already in the ungrouped bucket', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'Doing');

    /*
     * Both cards sit at the same position, which is legal: a position is unique inside its
     * bucket, and these are in two. Leaving the move to `section_id`'s `nullOnDelete` would
     * null the column and keep the position, so the two would land in one slot and the slot
     * guard would refuse the whole delete.
     */
    $ungrouped = TaskProjectMembership::factory()
        ->placing(Task::factory()->in($project->workspace)->create(['title' => 'Loose']), $project)
        ->at(SparsePosition::GAP)
        ->create();

    TaskProjectMembership::factory()
        ->placing(Task::factory()->in($project->workspace)->create(['title' => 'Boxed']), $project)
        ->inSection($section)
        ->at(SparsePosition::GAP)
        ->create();

    app(DeleteSection::class)->handle($section, $actor);

    $order = $project->placements()->whereNull('section_id')->orderBy('position')->with('task')->get();

    expect($order->pluck('task.title')->all())->toBe(['Loose', 'Boxed'])
        ->and($order->pluck('position')->unique())->toHaveCount(2)
        ->and($ungrouped->refresh()->position)->toBe(SparsePosition::GAP);
});

it('leaves the cards of another column alone', function (): void {
    [$project, $actor] = projectEditableBy();
    $going = addSection($project, $actor, 'Going');
    $staying = addSection($project, $actor, 'Staying');

    $kept = TaskProjectMembership::factory()
        ->placing(Task::factory()->in($project->workspace)->create(), $project)
        ->inSection($staying)
        ->create();

    TaskProjectMembership::factory()
        ->placing(Task::factory()->in($project->workspace)->create(), $project)
        ->inSection($going)
        ->create();

    app(DeleteSection::class)->handle($going, $actor);

    expect($staying->placements()->pluck('id')->all())->toBe([$kept->id])
        ->and($kept->refresh()->position)->toBe($kept->position);
});
