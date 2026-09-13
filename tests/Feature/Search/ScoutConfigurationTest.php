<?php

declare(strict_types=1);

use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\CollectionEngine;

it('indexes after the transaction commits, never inside it', function (): void {
    expect(config('scout.after_commit'))->toBeTrue();
});

it('queues indexing onto a queue Horizon supervises', function (): void {
    $queue = config('scout.queue.queue');

    expect($queue)->toBe('search')
        ->and(config('horizon.defaults.supervisor-1.queue'))->toContain($queue)
        ->and(config('horizon.waits'))->toHaveKey("redis:{$queue}");
});

it('leaves the queue connection to the environment', function (): void {
    expect(config('scout.queue.connection'))->toBeNull();
});

it('takes a soft-deleted record out of the index', function (): void {
    expect(config('scout.soft_delete'))->toBeFalse();
});

it('runs the suite without a search engine', function (): void {
    expect(config('scout.driver'))->toBe('collection')
        ->and(app(EngineManager::class)->engine())->toBeInstanceOf(CollectionEngine::class);
});
