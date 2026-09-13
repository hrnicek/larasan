<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Queries\CurrentWorkspace;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Testing\AssertableInertia;

it('opens both personal screens for every role', function (WorkspaceRole $role): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);

    $this->actingAs($actor)->get(route('my-tasks.index'))->assertOk();
    $this->actingAs($actor)->get(route('inbox.index'))->assertOk();
})->with([
    'owner' => [WorkspaceRole::Owner],
    'admin' => [WorkspaceRole::Admin],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it('turns away a revoked membership at both screens', function (WorkspaceRole $role): void {
    $workspace = Workspace::factory()->create();
    $revoked = memberOf($workspace, $role, WorkspaceMembershipStatus::Revoked);

    $this->actingAs($revoked)->get(route('my-tasks.index'))->assertNotFound();
    $this->actingAs($revoked)->get(route('inbox.index'))->assertNotFound();
})->with([
    'owner' => [WorkspaceRole::Owner],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it("keeps one person's notifications out of everybody else's reach", function (WorkspaceRole $role): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Owner);
    $reader = memberOf($workspace, WorkspaceRole::Member);
    $other = memberOf($workspace, $role);

    assignTo($workspace, $actor, $reader);
    $notification = DatabaseNotification::query()->where('notifiable_id', $reader->id)->sole();

    $this->actingAs($other)
        ->get(route('inbox.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('notifications', 0));

    $this->actingAs($other)->put(route('inbox.read', $notification))->assertNotFound();
    $this->actingAs($other)->put(route('inbox.read-all'))->assertRedirect();

    expect($notification->fresh()?->read_at)->toBeNull();
})->with([
    'owner' => [WorkspaceRole::Owner],
    'admin' => [WorkspaceRole::Admin],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it('changes both screens when the workspace changes', function (): void {
    $workspace = Workspace::factory()->create();
    $elsewhere = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Owner);
    $reader = memberOf($workspace);
    memberOf($elsewhere, user: $reader);
    memberOf($elsewhere, user: $actor);

    Task::factory()->in($workspace)->create(['title' => 'Here', 'assignee_id' => $reader->id, 'due_at' => now()]);
    Task::factory()->in($elsewhere)->create(['title' => 'There', 'assignee_id' => $reader->id, 'due_at' => now()]);
    assignTo($workspace, $actor, $reader, 'Told here');
    assignTo($elsewhere, $actor, $reader, 'Told there');

    $this->actingAs($reader)
        ->get(route('my-tasks.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('tasks', 1)
            ->where('tasks.0.title', 'Here'));

    $this->actingAs($reader)
        ->get(route('inbox.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('notifications', 1)
            ->where('notifications.0.subject.title', 'Told here')
            ->where('unreadNotifications', 1));

    $reader->forceFill(['current_workspace_id' => $elsewhere->id])->save();

    // `CurrentWorkspace` memoises per request, and a test makes several requests through one container.
    app(CurrentWorkspace::class)->flush();

    $this->actingAs($reader->refresh())
        ->get(route('my-tasks.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('tasks.0.title', 'There'));

    $this->actingAs($reader->refresh())
        ->get(route('inbox.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('notifications.0.subject.title', 'Told there')
            ->where('unreadNotifications', 1));
});

it('turns away everybody who is not signed in', function (string $route): void {
    $this->get(route($route))->assertRedirect(route('login'));
})->with([
    'my tasks' => ['my-tasks.index'],
    'inbox' => ['inbox.index'],
]);
