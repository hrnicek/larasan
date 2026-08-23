<?php

declare(strict_types=1);

use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Testing\AssertableInertia;

/**
 * The one notification waiting for this person.
 */
function notificationFor(User $reader): DatabaseNotification
{
    return DatabaseNotification::query()
        ->where('notifiable_type', 'user')
        ->where('notifiable_id', $reader->id)
        ->sole();
}

it('marks one notification read', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    assignTo($workspace, $actor, $reader);

    $notification = notificationFor($reader);

    $this->actingAs($reader)
        ->put(route('inbox.read', $notification))
        ->assertRedirect();

    expect($notification->fresh()?->read_at)->not->toBeNull();
});

it('keeps the moment they first saw it', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    assignTo($workspace, $actor, $reader);
    $notification = notificationFor($reader);

    $this->actingAs($reader)->put(route('inbox.read', $notification))->assertRedirect();
    $first = $notification->fresh()?->read_at;

    $this->travel(5)->minutes();

    $this->actingAs($reader)->put(route('inbox.read', $notification))->assertRedirect();

    /*
     * Reading something twice does not unread it, and `read_at` is when they first saw it rather
     * than when they last clicked. A second request is answered by the state already being true.
     */
    expect($notification->fresh()?->read_at?->toIso8601String())->toBe($first?->toIso8601String());
});

it('refuses to let anybody mark somebody else s notification read', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    $other = memberOf($workspace);
    assignTo($workspace, $actor, $reader);
    $notification = notificationFor($reader);

    // 404 rather than 403: a notification is addressed to one person, so its existence is not
    // something anybody else is entitled to confirm.
    $this->actingAs($other)
        ->put(route('inbox.read', $notification))
        ->assertNotFound();

    expect($notification->fresh()?->read_at)->toBeNull();
});

it('refuses a notification from another workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $elsewhere = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    memberOf($elsewhere, user: $reader);
    memberOf($elsewhere, user: $actor);

    assignTo($elsewhere, $actor, $reader);
    $notification = notificationFor($reader);

    // The reader is standing in `$workspace`, so a notification from the other one is not
    // theirs to act on from here.
    $this->actingAs($reader)
        ->put(route('inbox.read', $notification))
        ->assertNotFound();

    expect($notification->fresh()?->read_at)->toBeNull();
});

it('turns away everybody who is not signed in', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    assignTo($workspace, $actor, $reader);

    $this->put(route('inbox.read', notificationFor($reader)))->assertRedirect(route('login'));
});

it('shows the read state the server decided', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    assignTo($workspace, $actor, $reader);
    $notification = notificationFor($reader);

    $this->actingAs($reader)->put(route('inbox.read', $notification));

    // The screen re-renders from what came back rather than guessing (`docs/ui/inbox.md`).
    $this->actingAs($reader)
        ->get(route('inbox.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('notifications.0.read', true)
            ->where('meta.unread', 0));
});
