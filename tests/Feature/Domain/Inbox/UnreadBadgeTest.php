<?php

declare(strict_types=1);

use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

it('shares the unread count with every screen', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    assignTo($workspace, $actor, $reader);
    assignTo($workspace, $actor, $reader);

    // Shared, because the badge is in the shell rather than on a page: every screen has to be
    // able to draw it without asking for it.
    $this->actingAs($reader)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('unreadNotifications', 2));
});

it('counts only this workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $elsewhere = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    memberOf($elsewhere, user: $reader);
    memberOf($elsewhere, user: $actor);

    assignTo($workspace, $actor, $reader);
    assignTo($elsewhere, $actor, $reader);
    assignTo($elsewhere, $actor, $reader);

    $this->actingAs($reader)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('unreadNotifications', 1));
});

it('drops as soon as something is read', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    assignTo($workspace, $actor, $reader);
    assignTo($workspace, $actor, $reader);

    $notification = DatabaseNotification::query()->where('notifiable_id', $reader->id)->firstOrFail();

    $this->actingAs($reader)->put(route('inbox.read', $notification))->assertRedirect();

    // The badge follows the server's answer rather than being decremented by the client.
    $this->actingAs($reader)
        ->get(route('inbox.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('unreadNotifications', 1));
});

it('asks nothing when nobody is signed in', function (): void {
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->get(route('home'))->assertOk();

    // A query to answer "zero" is a query nobody needed, and this one would run on every
    // request of a public page.
    expect(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'notifications')))->toBeEmpty();
});

it('costs one query for the badge', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    assignTo($workspace, $actor, $reader);

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($reader)->get(route('dashboard'))->assertOk();

    expect(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'from "notifications"')))
        ->toHaveCount(1);
});
