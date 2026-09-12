<?php

declare(strict_types=1);

use App\Domain\File\Events\FileAttached;
use App\Domain\File\Listeners\GenerateThumbnail;
use App\Domain\Workspace\Listeners\ClaimInvitationsForVerifiedEmail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * @return list<string>
 */
function rawListenersFor(string $event): array
{
    return array_values(array_filter(
        Event::getRawListeners()[$event] ?? [],
        is_string(...),
    ));
}

it('generates a thumbnail when a file is attached', function (): void {
    expect(Event::hasListeners(FileAttached::class))->toBeTrue()
        ->and(rawListenersFor(FileAttached::class))->toBe([GenerateThumbnail::class]);
});

it('sends exactly one verification email on registration', function (): void {
    $sends = array_filter(
        rawListenersFor(Registered::class),
        fn (string $listener): bool => $listener === SendEmailVerificationNotification::class,
    );

    expect($sends)->toHaveCount(1);
});

it('claims invitations once an address is verified, never at registration', function (): void {
    expect(rawListenersFor(Verified::class))->toContain(ClaimInvitationsForVerifiedEmail::class)
        ->and(rawListenersFor(Registered::class))->not->toContain(ClaimInvitationsForVerifiedEmail::class);
});

it('registers every domain listener for some event', function (): void {
    $registered = collect(Event::getRawListeners())
        ->flatten()
        ->filter(fn (mixed $listener): bool => is_string($listener))
        ->unique();

    $listeners = collect(glob(app_path('Domain/*/Listeners/*.php')) ?: [])
        ->map(fn (string $path): string => 'App\\'.Str::of($path)
            ->after(app_path().DIRECTORY_SEPARATOR)
            ->beforeLast('.php')
            ->replace(DIRECTORY_SEPARATOR, '\\'));

    expect($listeners)->not->toBeEmpty()
        ->and($listeners->diff($registered)->values()->all())->toBe([]);
});
