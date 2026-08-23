<?php

declare(strict_types=1);

use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Support\SessionKey;

/**
 * @return array<int, DatabaseNotification>
 */
function inboxOfReader(User $reader): array
{
    return DatabaseNotification::query()
        ->where('notifiable_type', 'user')
        ->where('notifiable_id', $reader->id)
        ->get()
        ->all();
}

it('clears the inbox in one request', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);

    foreach (range(1, 3) as $index) {
        assignTo($workspace, $actor, $reader, "Task {$index}");
    }

    $this->actingAs($reader)->put(route('inbox.read-all'))->assertRedirect();

    expect(collect(inboxOfReader($reader))->whereNull('read_at'))->toBeEmpty();
});

it('leaves the moment they first saw the ones they had already read', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    assignTo($workspace, $actor, $reader, 'Seen');
    assignTo($workspace, $actor, $reader, 'Unseen');

    $seen = DatabaseNotification::query()->where('notifiable_id', $reader->id)->firstOrFail();
    $this->actingAs($reader)->put(route('inbox.read', $seen));
    $originally = $seen->fresh()?->read_at?->toIso8601String();

    $this->travel(10)->minutes();

    $this->actingAs($reader)->put(route('inbox.read-all'))->assertRedirect();

    // "Mark all read" must not rewrite when somebody first saw the things they had already seen.
    expect($seen->fresh()?->read_at?->toIso8601String())->toBe($originally);
});

it('leaves another workspace s inbox alone', function (): void {
    $workspace = Workspace::factory()->create();
    $elsewhere = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    memberOf($elsewhere, user: $reader);
    memberOf($elsewhere, user: $actor);

    assignTo($workspace, $actor, $reader, 'Here');
    assignTo($elsewhere, $actor, $reader, 'There');

    $this->actingAs($reader)->put(route('inbox.read-all'))->assertRedirect();

    /*
     * Clearing the inbox somebody is standing in must not hide things in one they have not
     * looked at.
     */
    $unread = DatabaseNotification::query()
        ->where('notifiable_id', $reader->id)
        ->whereNull('read_at')
        ->get();

    expect($unread)->toHaveCount(1)
        ->and($unread->first()?->workspace_id)->toBe($elsewhere->id);
});

it('leaves everybody else s inbox alone', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    $other = memberOf($workspace);

    assignTo($workspace, $actor, $reader);
    assignTo($workspace, $actor, $other);

    $this->actingAs($reader)->put(route('inbox.read-all'))->assertRedirect();

    expect(collect(inboxOfReader($other))->whereNull('read_at'))->toHaveCount(1);
});

it('says how many it cleared', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    assignTo($workspace, $actor, $reader);
    assignTo($workspace, $actor, $reader);

    $this->actingAs($reader)->put(route('inbox.read-all'))->assertRedirect();

    /** @var array<string, array<string, string>> $flashed */
    $flashed = session()->get(SessionKey::FLASH_DATA, []);

    // Read through Inertia's own session key: the toast is what tells somebody the request did
    // something, and a count nobody sees is a count nobody can trust.
    expect($flashed['toast']['message'])->toBe('Marked 2 notifications read');
});

it('is harmless on an inbox that is already clear', function (): void {
    $workspace = Workspace::factory()->create();
    $reader = memberOf($workspace);

    // Nothing to do is not an error; it is the outcome somebody asked for.
    $this->actingAs($reader)->put(route('inbox.read-all'))->assertRedirect();
});

it('turns away everybody who is not signed in', function (): void {
    $this->put(route('inbox.read-all'))->assertRedirect(route('login'));
});
