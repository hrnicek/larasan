<?php

declare(strict_types=1);

it('watches every queue it supervises', function (): void {
    $supervised = config('horizon.defaults.supervisor-1.queue');
    $watched = array_keys(config('horizon.waits'));

    expect($watched)->toEqualCanonicalizing(
        array_map(static fn (string $queue): string => "redis:{$queue}", $supervised),
    );
})->with([
    'a queue nobody watches is a queue nobody notices stopping',
]);

it('gives a board update a shorter fuse than an email', function (): void {
    expect(config('horizon.waits.redis:broadcasts'))
        ->toBeLessThan(config('horizon.waits.redis:notifications'));
})->with([
    'a board update that has waited ten seconds has stopped being an update; a late email is an
    inconvenience',
]);

it('names every environment this application runs under', function (): void {
    expect(array_keys(config('horizon.environments')))
        ->toEqualCanonicalizing(['production', 'local', 'testing']);
})->with([
    'an environment missing from the block leaves Horizon with no supervisor and says nothing
    about it — the queue simply stops being worked',
]);

it('gives every environment the supervisor the defaults describe', function (): void {
    $withoutSupervisor = array_keys(array_filter(
        config('horizon.environments'),
        static fn (array $supervisors): bool => ! array_key_exists('supervisor-1', $supervisors),
    ));

    expect($withoutSupervisor)->toBe([]);
})->with([
    'an environment that overrides nothing still has to name the supervisor it is overriding',
]);
