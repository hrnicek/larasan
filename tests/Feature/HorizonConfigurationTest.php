<?php

declare(strict_types=1);

it('watches every queue it supervises', function (): void {
    $supervised = config('horizon.defaults.supervisor-1.queue');
    $watched = array_keys(config('horizon.waits'));

    expect($watched)->toEqualCanonicalizing(
        array_map(static fn (string $queue): string => "redis:{$queue}", $supervised),
    );
});

it('gives a board update a shorter fuse than an email', function (): void {
    expect(config('horizon.waits.redis:broadcasts'))
        ->toBeLessThan(config('horizon.waits.redis:notifications'));
});

it('names every environment this application runs under', function (): void {
    expect(array_keys(config('horizon.environments')))
        ->toEqualCanonicalizing(['production', 'local', 'testing']);
});

it('gives every environment the supervisor the defaults describe', function (): void {
    $withoutSupervisor = array_keys(array_filter(
        config('horizon.environments'),
        static fn (array $supervisors): bool => ! array_key_exists('supervisor-1', $supervisors),
    ));

    expect($withoutSupervisor)->toBe([]);
});
