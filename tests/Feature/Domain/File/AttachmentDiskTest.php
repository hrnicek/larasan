<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('keeps attachments on a disk the framework cannot serve', function (): void {
    $disk = (string) config('filesystems.attachments');

    /*
     * The `local` disk is registered with `serve => true`, which gives the framework a route
     * into it. That route is signature gated, so it is not a hole today — but a disk the
     * framework can serve is one signed URL away from bypassing the reach check every download
     * goes through (ADR-0007). Attachments therefore live on a disk with no route at all.
     */
    expect(config("filesystems.disks.{$disk}.serve"))->toBeFalse();
});

it('has no route that serves the attachments disk', function (): void {
    $disk = (string) config('filesystems.attachments');

    $serving = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains((string) $route->getName(), "storage.{$disk}"));

    // `storage.local` exists and is fine; there must be no `storage.attachments`.
    expect($serving)->toBeEmpty();
});
