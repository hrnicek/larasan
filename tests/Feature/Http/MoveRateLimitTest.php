<?php

declare(strict_types=1);

use App\Domain\Section\Models\Section;
use App\Domain\Task\Models\Task;

/**
 * Moving a card or a column locks the whole column for the length of its transaction, and the
 * board sends one request per drop — the first endpoints here that are both frequent and
 * expensive (TASK-070-016).
 */
it('lets an ordinary burst of drags through', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    // Twenty drops in a row is a person rearranging a board, not a runaway client.
    foreach (range(1, 20) as $ignored) {
        $this->actingAs($actor)
            ->put(route('placements.move', $placement), ['section' => $section->id])
            ->assertRedirect();
    }
});

it('stops a client that will not stop', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    /*
     * A payload the request refuses, so each attempt is cheap — the limiter runs before
     * validation, which is the point: a loop is bounded whether or not its requests are valid.
     */
    foreach (range(1, 60) as $ignored) {
        $this->actingAs($actor)->put(route('placements.move', $placement), ['section' => 'nonsense']);
    }

    $this->actingAs($actor)
        ->put(route('placements.move', $placement), ['section' => 'nonsense'])
        ->assertStatus(429);
});

it('limits the column move endpoint the same way', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'Backlog');

    foreach (range(1, 60) as $ignored) {
        $this->actingAs($actor)->put(route('sections.move', $section), ['after' => 'nonsense']);
    }

    $this->actingAs($actor)
        ->put(route('sections.move', $section), ['after' => 'nonsense'])
        ->assertStatus(429);
});

it('counts each person separately', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);
    [, , $other] = placeableProject();

    foreach (range(1, 61) as $ignored) {
        $this->actingAs($actor)->put(route('placements.move', $placement), ['section' => 'nonsense']);
    }

    // One person hitting the limit must not stop anybody else from working.
    $this->actingAs($other)
        ->put(route('placements.move', $placement), ['section' => 'nonsense'])
        ->assertNotFound();
});
