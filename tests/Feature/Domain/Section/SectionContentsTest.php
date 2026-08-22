<?php

declare(strict_types=1);

use App\Domain\Placement\Actions\AttachTaskToProject;
use App\Domain\Placement\Actions\DetachTaskFromProject;
use App\Domain\Placement\Actions\MoveTaskInProject;
use App\Domain\Placement\Exceptions\PlacementException;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Actions\ArchiveProject;
use App\Domain\Section\Actions\DeleteSection;
use App\Domain\Section\Actions\RenameSection;
use App\Domain\Section\Data\UpdateSectionData;
use App\Domain\Section\Exceptions\SectionException;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Actions\DeleteTask;
use App\Domain\Task\Models\Task;

/**
 * What a column contains once a task can be soft-deleted and a project can be archived
 * (TASK-050-013). The rows and what is drawn from them are two different questions.
 */
it('keeps the placement of a soft-deleted task and draws nothing for it', function (): void {
    [$project, $actor] = projectEditableBy(ProjectAccessLevel::Editor, WorkspaceRole::Owner);
    $section = addSection($project, $actor, 'Doing');
    $task = Task::factory()->in($project->workspace)->create();
    $placement = app(AttachTaskToProject::class)->handle($task, $project, $actor);
    app(MoveTaskInProject::class)->handle($placement, $actor, $section);

    app(DeleteTask::class)->handle($task, $actor);

    // The row survives, so restoring the task puts the card back where it was. Until then
    // there is nothing to draw: a board that rendered the row would render a card with no
    // task on it.
    expect($section->placements()->count())->toBe(1)
        ->and($section->placements()->visible()->count())->toBe(0);
});

it('puts the card back when the task is restored', function (): void {
    [$project, $actor] = projectEditableBy(ProjectAccessLevel::Editor, WorkspaceRole::Owner);
    $section = addSection($project, $actor, 'Doing');
    $task = Task::factory()->in($project->workspace)->create();
    $placement = app(AttachTaskToProject::class)->handle($task, $project, $actor);
    app(MoveTaskInProject::class)->handle($placement, $actor, $section);
    $slot = $placement->refresh()->position;

    app(DeleteTask::class)->handle($task, $actor);
    $task->restore();

    expect($section->placements()->visible()->count())->toBe(1)
        ->and($placement->refresh()->position)->toBe($slot)
        ->and($placement->section_id)->toBe($section->id);
});

it('counts a column the same way it draws it', function (): void {
    [$project, $actor] = projectEditableBy(ProjectAccessLevel::Editor, WorkspaceRole::Owner);
    $section = addSection($project, $actor, 'Doing');

    $tasks = collect(range(1, 3))->map(function () use ($project, $actor, $section): Task {
        $task = Task::factory()->in($project->workspace)->create();
        app(MoveTaskInProject::class)->handle(
            app(AttachTaskToProject::class)->handle($task, $project, $actor),
            $actor,
            $section,
        );

        return $task;
    });

    app(DeleteTask::class)->handle($tasks[1], $actor);

    // A header that counts rows and a column that draws cards must not disagree, which is
    // why both go through the same scope.
    $drawn = $section->placements()->visible()->get();

    expect($section->placements()->visible()->count())->toBe(2)
        ->and($drawn)->toHaveCount(2)
        ->and($section->placements()->count())->toBe(3);
});

it('refuses to change the columns of an archived project', function (): void {
    [$project, $actor] = projectEditableBy(ProjectAccessLevel::Owner, WorkspaceRole::Owner);
    $section = addSection($project, $actor, 'Doing');

    app(ArchiveProject::class)->archive($project, $actor);
    $project->refresh();

    // An archived project is a record of what happened, not a board somebody is still
    // working on.
    expect(fn (): Section => app(RenameSection::class)->handle($section->refresh(), $actor, new UpdateSectionData(name: 'Renamed')))
        ->toThrow(SectionException::class);

    expect(fn () => app(DeleteSection::class)->handle($section->refresh(), $actor))
        ->toThrow(SectionException::class);

    expect($section->fresh()?->name)->toBe('Doing');
});

it('refuses to change what an archived project holds', function (): void {
    [$project, $actor] = projectEditableBy(ProjectAccessLevel::Owner, WorkspaceRole::Owner);
    $section = addSection($project, $actor, 'Doing');
    $task = Task::factory()->in($project->workspace)->create();
    $placement = app(AttachTaskToProject::class)->handle($task, $project, $actor);

    app(ArchiveProject::class)->archive($project, $actor);
    $project->refresh();

    $another = Task::factory()->in($project->workspace)->create();

    expect(fn (): TaskProjectMembership => app(AttachTaskToProject::class)->handle($another, $project, $actor))
        ->toThrow(PlacementException::class);

    expect(fn (): TaskProjectMembership => app(MoveTaskInProject::class)->handle($placement->refresh(), $actor, $section))
        ->toThrow(PlacementException::class);

    expect(fn () => app(DetachTaskFromProject::class)->handle($task, $project, $actor))
        ->toThrow(PlacementException::class);

    expect($project->placements()->count())->toBe(1);
});

it('lets an archived project be restored and edited again', function (): void {
    [$project, $actor] = projectEditableBy(ProjectAccessLevel::Owner, WorkspaceRole::Owner);
    $section = addSection($project, $actor, 'Doing');

    app(ArchiveProject::class)->archive($project, $actor);
    app(ArchiveProject::class)->restore($project->refresh(), $actor);

    // Restoring goes through `isManageableBy()`, not through the content rule, so an
    // archived project is never stuck.
    $renamed = app(RenameSection::class)->handle($section->refresh(), $actor, new UpdateSectionData(name: 'Renamed'));

    expect($renamed->name)->toBe('Renamed');
});
