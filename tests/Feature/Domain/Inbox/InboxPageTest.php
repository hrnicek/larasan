<?php

declare(strict_types=1);

use App\Domain\Workspace\Models\Workspace;
use App\Http\Middleware\HandleInertiaRequests;
use Inertia\Testing\AssertableInertia;

it('requires authentication', function (): void {
    $this->get(route('inbox.index'))->assertRedirect(route('login'));
});

it('renders what is waiting for the reader', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    assignTo($workspace, $actor, $reader, 'Fix login');

    $this->actingAs($reader)
        ->get(route('inbox.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('inbox/Index')
            ->has('notifications', 1)
            ->where('notifications.0.type', 'task.assigned')
            ->where('notifications.0.subject.title', 'Fix login')
            ->where('notifications.0.actor.id', $actor->id)
            ->where('meta.unread', 1));
});

it('renders an empty inbox as an empty inbox', function (): void {
    $workspace = Workspace::factory()->create();
    $reader = memberOf($workspace);

    $this->actingAs($reader)
        ->get(route('inbox.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('notifications', 0)
            ->where('meta.unread', 0));
});

it('shows the workspace the reader is standing in', function (): void {
    $workspace = Workspace::factory()->create();
    $elsewhere = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    memberOf($elsewhere, user: $reader);
    memberOf($elsewhere, user: $actor);

    assignTo($workspace, $actor, $reader, 'Here');
    assignTo($elsewhere, $actor, $reader, 'There');

    $this->actingAs($reader)
        ->get(route('inbox.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('notifications', 1)
            ->where('notifications.0.subject.title', 'Here'));
});

it('never renders somebody else s inbox', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    $other = memberOf($workspace);

    assignTo($workspace, $actor, $other, 'Theirs');

    $this->actingAs($reader)
        ->get(route('inbox.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('notifications', 0));
});

it('pages the inbox from the URL', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);

    foreach (range(1, 30) as $index) {
        assignTo($workspace, $actor, $reader, "Task {$index}");
    }

    $this->actingAs($reader)
        ->withoutMiddleware(HandleInertiaRequests::class)
        ->get(route('inbox.index', ['page' => 2]), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'inbox/Index',
            'X-Inertia-Partial-Data' => 'notifications,meta',
        ])
        ->assertOk()
        ->assertJsonPath('props.meta.page', 2)
        ->assertJsonCount(5, 'props.notifications');
});

it('says nothing about a task that has since been removed rather than linking nowhere', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    $task = assignTo($workspace, $actor, $reader, 'Deleted later');

    $task->delete();

    $this->actingAs($reader)
        ->get(route('inbox.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('notifications', 1)
            ->where('notifications.0.subject', null));
});
