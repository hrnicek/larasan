<?php

declare(strict_types=1);

use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\CollectionEngine;

it('indexes after the transaction commits, never inside it', function (): void {
    expect(config('scout.after_commit'))->toBeTrue();
})->with([
    'a rolled-back write must not leave a row in the index that PostgreSQL no longer has',
]);

it('queues indexing onto a queue Horizon supervises', function (): void {
    $queue = config('scout.queue.queue');

    expect($queue)->toBe('search')
        ->and(config('horizon.defaults.supervisor-1.queue'))->toContain($queue)
        ->and(config('horizon.waits'))->toHaveKey("redis:{$queue}");
})->with([
    'saving a task must not be held open by an HTTP call to a search service, and a queue nobody
    watches is a queue nobody notices stopping',
]);

it('leaves the queue connection to the environment', function (): void {
    expect(config('scout.queue.connection'))->toBeNull();
})->with([
    'the suite queues synchronously and must not reach for Redis to save a model',
]);

it('takes a soft-deleted record out of the index', function (): void {
    expect(config('scout.soft_delete'))->toBeFalse();
})->with([
    'search is how people find work, and work in the trash is not work',
]);

it('runs the suite without a search engine', function (): void {
    expect(config('scout.driver'))->toBe('collection')
        ->and(app(EngineManager::class)->engine())->toBeInstanceOf(CollectionEngine::class);
})->with([
    'the collection engine exercises the same Scout API and the same query() callback the reach
    rule lives in (ADR-0016)',
]);
