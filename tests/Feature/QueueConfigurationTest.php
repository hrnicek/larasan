<?php

declare(strict_types=1);

it('holds every job dispatched inside a transaction until it commits', function (): void {
    /** @var array<string, array{driver: string, after_commit?: bool}> $connections */
    $connections = config('queue.connections');

    // Failover has no queue of its own; it hands the job to the connections it lists.
    $queues = array_filter($connections, fn (array $settings): bool => $settings['driver'] !== 'failover');

    expect($queues)->not->toBeEmpty();

    foreach ($queues as $name => $settings) {
        expect($settings['after_commit'] ?? null)->toBeTrue("queue connection [{$name}] dispatches before commit");
    }
});
