<?php

declare(strict_types=1);

use App\Domain\Comment\Models\Comment;
use App\Domain\Task\Models\Task;
use Illuminate\Support\Facades\RateLimiter;

it('bounds how fast one account can comment', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    foreach (range(1, 30) as $index) {
        $this->actingAs($actor)
            ->post(route('tasks.comments.store', $task), ['body' => "Comment {$index}"])
            ->assertRedirect();
    }

    $this->actingAs($actor)
        ->post(route('tasks.comments.store', $task), ['body' => 'One too many'])
        ->assertStatus(429);

    expect($task->comments()->count())->toBe(30);
});

it('bounds edits by the same budget', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $comment = Comment::factory()->on($task)->by($actor)->create();

    foreach (range(1, 30) as $index) {
        $this->actingAs($actor)
            ->put(route('comments.update', $comment), ['body' => "Version {$index}"])
            ->assertRedirect();
    }

    $this->actingAs($actor)
        ->put(route('comments.update', $comment), ['body' => 'One too many'])
        ->assertStatus(429);
});

it('gives each account its own budget', function (): void {
    [$workspace, , $actor] = placeableProject();
    $other = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    foreach (range(1, 30) as $index) {
        $this->actingAs($actor)->post(route('tasks.comments.store', $task), ['body' => "Comment {$index}"]);
    }

    $this->actingAs($other)
        ->post(route('tasks.comments.store', $task), ['body' => 'Mine'])
        ->assertRedirect();
});

afterEach(function (): void {
    RateLimiter::clear('comments');
});
