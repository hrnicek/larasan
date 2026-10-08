<?php

declare(strict_types=1);

use App\Domain\Section\Models\Section;
use App\Domain\Task\Models\Task;

// A move locks the whole column for the length of its transaction, so move endpoints are rate limited.
it('lets an ordinary burst of drags through', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    foreach (range(1, 20) as $ignored) {
        $this->actingAs($actor)
            ->put(route('placements.move', $placement), ['section' => $section->id])
            ->assertRedirect();
    }
});

it('stops a client that will not stop', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $placement = attach(Task::factory()->in($workspace)->create(), $project, $actor);

    // The limiter runs before validation, so invalid requests still count.
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

    $this->actingAs($other)
        ->put(route('placements.move', $placement), ['section' => 'nonsense'])
        ->assertNotFound();
});
