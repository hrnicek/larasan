<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Http\Requests\Placement\MovePlacementRequest;
use App\Http\Requests\Placement\StorePlacementRequest;
use Illuminate\Support\Facades\Route;

/**
 * The endpoints arrive with TASK-070-011. Probe routes assert the requests for what they
 * are — validation and authorization — before a controller exists to confuse a failure with
 * a routing one.
 */
beforeEach(function (): void {
    Route::middleware('web')->post('placement-probe/{project}', fn (StorePlacementRequest $request, Project $project) => response()->json([
        'task' => $request->string('task')->toString(),
    ]));

    Route::middleware('web')->put('placement-probe/{placement}/move', fn (MovePlacementRequest $request, TaskProjectMembership $placement) => response()->json([
        'section' => $request->targetSection()?->id,
        'after' => $request->target()->after?->id,
        'front' => $request->target()->atFront,
    ]));
});

it('accepts a task a project editor asked to place', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->postJson("placement-probe/{$project->id}", ['task' => $task->id])
        ->assertOk()
        ->assertJson(['task' => $task->id]);
});

it('refuses placing to somebody who may only view the project', function (): void {
    [$workspace, $project, $viewer] = placeableProject(ProjectAccessLevel::Viewer);
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($viewer)
        ->postJson("placement-probe/{$project->id}", ['task' => $task->id])
        ->assertForbidden();
});

it('rejects a task from another workspace with a validation error', function (): void {
    [, $project, $actor] = placeableProject();
    $elsewhere = Task::factory()->create();

    // A valid id from another tenant is a bad request, not a domain exception — and the
    // scoped `exists` rule is what keeps it from confirming that the task is real.
    $this->actingAs($actor)
        ->postJson("placement-probe/{$project->id}", ['task' => $elsewhere->id])
        ->assertJsonValidationErrorFor('task')
        ->assertJsonFragment(['task' => ['That task is not in this workspace.']]);
});

it('rejects a placement with no task or an id that is not a uuid', function (array $payload): void {
    [, $project, $actor] = placeableProject();

    $this->actingAs($actor)
        ->postJson("placement-probe/{$project->id}", $payload)
        ->assertJsonValidationErrorFor('task');
})->with([
    'no task' => [[]],
    'not a uuid' => [['task' => 'the-first-one']],
]);

it('rejects a task that has been deleted', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $task->delete();

    // Soft-deleted is deleted as far as a board is concerned: a card for it would be a card
    // for something the workspace no longer has.
    $this->actingAs($actor)
        ->postJson("placement-probe/{$project->id}", ['task' => $task->id])
        ->assertJsonValidationErrorFor('task');
});

it('accepts a move into a column of the same project', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    $this->actingAs($actor)
        ->putJson("placement-probe/{$placement->id}/move", ['section' => $section->id])
        ->assertOk()
        ->assertJson(['section' => $section->id, 'after' => null, 'front' => false]);
});

it('accepts a null section as the ungrouped bucket', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    // Null is a column, not a missing answer (ADR-0004).
    $this->actingAs($actor)
        ->putJson("placement-probe/{$placement->id}/move", ['section' => null])
        ->assertOk()
        ->assertJson(['section' => null]);
});

it('requires the section field to be sent at all', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    // An omitted section and an explicit null are different requests: one says "put it
    // nowhere", the other says nothing.
    $this->actingAs($actor)
        ->putJson("placement-probe/{$placement->id}/move", [])
        ->assertJsonValidationErrorFor('section');
});

it('rejects a column from another project', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $foreign = Section::factory()->in(Project::factory()->in($workspace)->create())->create();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    $this->actingAs($actor)
        ->putJson("placement-probe/{$placement->id}/move", ['section' => $foreign->id])
        ->assertJsonValidationErrorFor('section')
        ->assertJsonFragment(['section' => ['That section is not in this project.']]);
});

it('accepts an anchor in the same column', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();
    $anchor = attach(Task::factory()->in($workspace)->create(), $project, $actor);
    moveInto($anchor, $actor, $section);
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    $this->actingAs($actor)
        ->putJson("placement-probe/{$placement->id}/move", ['section' => $section->id, 'after' => $anchor->id])
        ->assertOk()
        ->assertJson(['after' => $anchor->id]);
});

it('rejects an anchor sitting in another column', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();
    $ungrouped = attach(Task::factory()->in($workspace)->create(), $project, $actor);
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    // "After" is only meaningful among neighbours: the anchor is in the project but not in
    // the column the card is moving into.
    $this->actingAs($actor)
        ->putJson("placement-probe/{$placement->id}/move", ['section' => $section->id, 'after' => $ungrouped->id])
        ->assertJsonValidationErrorFor('after')
        ->assertJsonFragment(['after' => ['That card is not in this column.']]);
});

it('rejects a card placed after itself', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    $this->actingAs($actor)
        ->putJson("placement-probe/{$placement->id}/move", ['section' => null, 'after' => $placement->id])
        ->assertJsonValidationErrorFor('after')
        ->assertJsonFragment(['after' => ['A task cannot be placed after itself.']]);
});

it('reads the two ends of a column', function (string $at, bool $front): void {
    [$workspace, $project, $actor] = placeableProject();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    $this->actingAs($actor)
        ->putJson("placement-probe/{$placement->id}/move", ['section' => null, 'at' => $at])
        ->assertOk()
        ->assertJson(['front' => $front]);
})->with([
    'the front' => ['front', true],
    'the end' => ['end', false],
]);

it('rejects an end that is neither of the two', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    $this->actingAs($actor)
        ->putJson("placement-probe/{$placement->id}/move", ['section' => null, 'at' => 'middle'])
        ->assertJsonValidationErrorFor('at');
});

it('rejects an end sent alongside an anchor', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $anchor = attach(Task::factory()->in($workspace)->create(), $project, $actor);
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    // Meaningless together, so sending both is a mistake rather than a precedence rule
    // nobody would remember.
    $this->actingAs($actor)
        ->putJson("placement-probe/{$placement->id}/move", [
            'section' => null,
            'after' => $anchor->id,
            'at' => 'front',
        ])
        ->assertJsonValidationErrorFor('at');
});

it('refuses a move to somebody who may only view the project', function (): void {
    [$workspace, $project, $viewer] = placeableProject(ProjectAccessLevel::Viewer);
    $placement = TaskProjectMembership::factory()
        ->placing(Task::factory()->in($workspace)->create(), $project)
        ->create();

    $this->actingAs($viewer)
        ->putJson("placement-probe/{$placement->id}/move", ['section' => null])
        ->assertForbidden();
});

it('refuses a move to a guest who was given editor access to the project', function (): void {
    [$workspace, $project, $guest] = placeableProject(ProjectAccessLevel::Editor, WorkspaceRole::Guest);
    $placement = TaskProjectMembership::factory()
        ->placing(Task::factory()->in($workspace)->create(), $project)
        ->create();

    // Both halves have to pass: the project says yes, the workspace role does not carry
    // `task.update` (ADR-0006 with ADR-0010).
    $this->actingAs($guest)
        ->putJson("placement-probe/{$placement->id}/move", ['section' => null])
        ->assertForbidden();
});
