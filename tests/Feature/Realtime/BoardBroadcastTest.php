<?php

declare(strict_types=1);

use App\Domain\Placement\Events\TaskAttachedToProject;
use App\Domain\Placement\Events\TaskDetachedFromProject;
use App\Domain\Placement\Events\TaskPlacementMoved;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Events\ProjectArchived;
use App\Domain\Project\Events\ProjectUpdated;
use App\Domain\Project\Models\Project;
use App\Domain\Section\Events\SectionCreated;
use App\Domain\Section\Events\SectionDeleted;
use App\Domain\Section\Events\SectionMoved;
use App\Domain\Section\Events\SectionUpdated;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Broadcasting\ViewInvalidated;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Event;

it('tells the project a card arrived, and the workspace that it is no longer loose', function (): void {
    Event::fake([ViewInvalidated::class]);
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();
    $project = Project::factory()->in($workspace)->create();
    $placement = TaskProjectMembership::factory()->placing($task, $project)->create();

    event(new TaskAttachedToProject($placement->id, $task->id, $project->id, $actor->id));

    Event::assertDispatched(
        ViewInvalidated::class,
        fn (ViewInvalidated $broadcast): bool => $broadcast->change === 'placement.attached'
            && $broadcast->channels === ["project.{$project->id}"],
    );
});

it('tells both the column a card left and the workspace it fell back to', function (): void {
    Event::fake([ViewInvalidated::class]);
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();
    $project = Project::factory()->in($workspace)->create();

    // The row is already gone by the time the event is dispatched, which is exactly the case
    // this listener has to answer: the column has to lose the card, and the loose list has to
    // gain it.
    event(new TaskDetachedFromProject($task->id, $project->id, $actor->id));

    Event::assertDispatched(ViewInvalidated::class, function (ViewInvalidated $broadcast) use ($project, $workspace): bool {
        expect($broadcast->channels)
            ->toContain("project.{$project->id}")
            ->toContain("workspace.{$workspace->id}");

        return $broadcast->change === 'placement.detached';
    });
});

it('tells only the project when a card moves inside it', function (): void {
    Event::fake([ViewInvalidated::class]);
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $placement = TaskProjectMembership::factory()->placing($task, $project)->create();

    event(new TaskPlacementMoved($placement->id, $task->id, $project->id, null, $actor->id));

    Event::assertDispatched(
        ViewInvalidated::class,
        fn (ViewInvalidated $broadcast): bool => $broadcast->channels === ["project.{$project->id}"],
    );
})->with([
    'a private projects board must not announce its cards to the workspace',
]);

it('announces every section change on the projects channel alone', function (string $eventClass, string $change): void {
    Event::fake([ViewInvalidated::class]);
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create();
    $section = Section::factory()->in($project)->create();

    $event = match ($eventClass) {
        SectionCreated::class => new SectionCreated($section->id, $project->id, $actor->id),
        SectionUpdated::class => new SectionUpdated($section->id, $project->id, ['name']),
        SectionMoved::class => new SectionMoved($section->id, $project->id, null),
        SectionDeleted::class => new SectionDeleted($section->id, $project->id, $actor->id),
        default => throw new InvalidArgumentException("the dataset names [{$eventClass}] and this test does not build it"),
    };

    event($event);

    Event::assertDispatched(
        ViewInvalidated::class,
        fn (ViewInvalidated $broadcast): bool => $broadcast->change === $change
            && $broadcast->subjectType === 'section'
            && $broadcast->subjectId === $section->id
            && $broadcast->channels === ["project.{$project->id}"],
    );
})->with([
    'created' => [SectionCreated::class, 'section.created'],
    'updated' => [SectionUpdated::class, 'section.updated'],
    'moved' => [SectionMoved::class, 'section.moved'],
    'deleted' => [SectionDeleted::class, 'section.deleted'],
]);

it('tells the workspace about a visible project and only the project about a private one', function (): void {
    Event::fake([ViewInvalidated::class]);
    $workspace = Workspace::factory()->create();
    $visible = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Workspace]);
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);

    event(new ProjectUpdated($visible->id, ['name']));
    event(new ProjectUpdated($private->id, ['name']));

    Event::assertDispatched(
        ViewInvalidated::class,
        fn (ViewInvalidated $broadcast): bool => $broadcast->subjectId === $visible->id
            && $broadcast->channels === ["project.{$visible->id}", "workspace.{$workspace->id}"],
    );

    Event::assertDispatched(
        ViewInvalidated::class,
        fn (ViewInvalidated $broadcast): bool => $broadcast->subjectId === $private->id
            && $broadcast->channels === ["project.{$private->id}"],
    );
})->with([
    'a private projects name in a workspace payload is the existence of the project, which
    nobody outside it may learn',
]);

it('names archiving and restoring as the two different things they are', function (): void {
    Event::fake([ViewInvalidated::class]);
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create();

    event(new ProjectArchived($project->id, $actor->id, archived: true));
    event(new ProjectArchived($project->id, $actor->id, archived: false));

    Event::assertDispatched(ViewInvalidated::class, fn (ViewInvalidated $b): bool => $b->change === 'project.archived');
    Event::assertDispatched(ViewInvalidated::class, fn (ViewInvalidated $b): bool => $b->change === 'project.restored');
});

it('says nothing when the project it was told about is gone', function (): void {
    Event::fake([ViewInvalidated::class]);

    event(new ProjectUpdated('01a00000-0000-7000-8000-000000000000', ['name']));

    Event::assertDispatched(
        ViewInvalidated::class,
        fn (ViewInvalidated $broadcast): bool => $broadcast->channels === [],
    );
})->with([
    'a broadcast with no channel is delivered nowhere, which is the right amount of noise for
    a subject nobody can look at',
]);

it('reaches the board through the endpoint a drag actually calls', function (): void {
    Event::fake([ViewInvalidated::class]);
    [$workspace, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    $this->actingAs($actor)
        ->put(route('placements.move', $placement), ['section' => $section->id])
        ->assertRedirect();

    Event::assertDispatched(
        ViewInvalidated::class,
        fn (ViewInvalidated $broadcast): bool => $broadcast->change === 'placement.moved'
            && $broadcast->channels === ["project.{$project->id}"]
            && $broadcast->actorId === $actor->id,
    );
})->with([
    'the listeners are registered in a map nobody reads at runtime unless it is exercised, so
    one case goes the whole way: request, action, domain event, listener, broadcast',
]);
