<?php

declare(strict_types=1);

use App\Domain\Workspace\Data\CreateWorkspaceData;

it('defaults the slug to nothing and the timezone to UTC', function (): void {
    $data = new CreateWorkspaceData(name: 'Acme');

    expect($data->slug)->toBeNull()
        ->and($data->timezone)->toBe('UTC');
});

it('keeps a slug the caller chose', function (): void {
    $data = new CreateWorkspaceData(name: 'Acme', slug: 'acme-eu', timezone: 'Europe/Prague');

    expect($data->slug)->toBe('acme-eu')
        ->and($data->timezone)->toBe('Europe/Prague');
});
