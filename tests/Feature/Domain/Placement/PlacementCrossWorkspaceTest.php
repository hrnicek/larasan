<?php

declare(strict_types=1);

use App\Domain\Placement\Data\PlacementTarget;
use App\Domain\Placement\Exceptions\PlacementException;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Section\Models\Section;
use App\Domain\Task\Models\Task;

/**
 * The boundary no foreign key can express. Every id here points at a real row; what is wrong
 * is the combination, so the database accepts each one and the domain has to refuse the pair
 * (ADR-0003, ADR-0005).
 *
 * The cross-workspace attach is proven in `AttachTaskToProjectTest`, including by mutation.
 * What is added here is the other two ends of the same boundary — the column and the anchor
 * — and the endpoints, where the same refusals have to survive routing.
 */
it('refuses a column from another workspace', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    [, $elsewhere] = placeableProject();
    $foreign = Section::factory()->in($elsewhere)->create();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    expect(fn (): TaskProjectMembership => moveTo($placement, $actor, $foreign, PlacementTarget::end()))
        ->toThrow(PlacementException::class, 'That section is not in this project.');

    expect($placement->refresh()->section_id)->toBeNull();
});

it('refuses an anchor from another workspace', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    [$otherWorkspace, $elsewhere, $otherActor] = placeableProject();
    $foreign = attach(Task::factory()->in($otherWorkspace)->create(), $elsewhere, $otherActor);
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    expect(fn (): TaskProjectMembership => moveTo($placement, $actor, null, PlacementTarget::after($foreign)))
        ->toThrow(PlacementException::class, 'That card is not in this column.');
});

it('refuses a column from another workspace over http', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    [, $elsewhere] = placeableProject();
    $foreign = Section::factory()->in($elsewhere)->create();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    // A validation error rather than a domain exception: the request layer is where a stale
    // or hostile id is supposed to stop.
    $this->actingAs($actor)
        ->from(route('dashboard'))
        ->put(route('placements.move', $placement), ['section' => $foreign->id])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors('section');

    expect($placement->refresh()->section_id)->toBeNull();
});

it('refuses an anchor from another workspace over http', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    [$otherWorkspace, $elsewhere, $otherActor] = placeableProject();
    $foreign = attach(Task::factory()->in($otherWorkspace)->create(), $elsewhere, $otherActor);
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    $this->actingAs($actor)
        ->from(route('dashboard'))
        ->put(route('placements.move', $placement), ['section' => null, 'after' => $foreign->id])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors('after');
});

it('refuses to attach a task from another workspace through the action itself', function (): void {
    [, $project, $actor] = placeableProject();
    [$otherWorkspace] = placeableProject();
    $foreign = Task::factory()->in($otherWorkspace)->create();

    // The endpoint stops this with validation; the Action stops it for the console command,
    // the queued job and the future API, which never pass a FormRequest.
    expect(fn (): TaskProjectMembership => attach($foreign, $project, $actor))
        ->toThrow(PlacementException::class, 'That task is not in this workspace.');
});

it('leaves nothing behind in the other workspace after every refusal', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    [$otherWorkspace, $elsewhere, $otherActor] = placeableProject();
    $theirs = attach(Task::factory()->in($otherWorkspace)->create(), $elsewhere, $otherActor);
    $mine = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    rescue(fn (): TaskProjectMembership => moveTo($mine, $actor, Section::factory()->in($elsewhere)->create(), PlacementTarget::end()));
    rescue(fn (): TaskProjectMembership => moveTo($mine, $actor, null, PlacementTarget::after($theirs)));

    expect($elsewhere->placements()->pluck('id')->all())->toBe([$theirs->id])
        ->and($theirs->refresh()->position)->toBe($theirs->position)
        ->and($project->placements()->pluck('id')->all())->toBe([$mine->id]);
});
