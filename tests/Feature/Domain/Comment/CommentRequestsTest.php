<?php

declare(strict_types=1);

use App\Domain\Comment\Data\CreateCommentData;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Requests\Comment\StoreCommentRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Route::middleware('web')->post('comment-probe/{task}', fn (StoreCommentRequest $request, Task $task) => response()->json([
        'body' => CreateCommentData::fromRequest($request)->body,
        'subject' => $request->subject()?->getKey(),
    ]));

    // Unscoped binding, so the request can be handed a subject outside the current workspace.
    Route::middleware('web')->post('comment-probe/any/{subject}', fn (StoreCommentRequest $request, Task $subject) => response()->json([
        'subject' => $request->subject()?->getKey(),
    ]));
});

it('accepts a comment from somebody who can reach the task', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->postJson("comment-probe/{$task->id}", ['body' => 'Looks right to me'])
        ->assertOk()
        ->assertJson(['body' => 'Looks right to me', 'subject' => $task->id]);
});

it('finds the subject the route bound rather than one the payload names', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $elsewhere = Task::factory()->create();

    $this->actingAs($actor)
        ->postJson("comment-probe/{$task->id}", ['body' => 'Fine', 'commentable_id' => $elsewhere->id])
        ->assertOk()
        ->assertJson(['subject' => $task->id]);
});

it('requires something to say, and not too much of it', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->postJson("comment-probe/{$task->id}", [])
        ->assertJsonValidationErrorFor('body');

    $this->actingAs($actor)
        ->postJson("comment-probe/{$task->id}", ['body' => Str::repeat('a', 5001)])
        ->assertJsonValidationErrorFor('body');
});

it('refuses somebody who cannot reach the task', function (): void {
    $workspace = Workspace::factory()->create();
    $outsider = memberOf($workspace, WorkspaceRole::Member);
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();

    $this->actingAs($outsider)
        ->postJson("comment-probe/{$task->id}", ['body' => 'Looks right to me'])
        ->assertForbidden();
});

it('refuses somebody whose membership is no longer live', function (): void {
    $workspace = Workspace::factory()->create();
    $revoked = memberOf($workspace, WorkspaceRole::Member, WorkspaceMembershipStatus::Revoked);
    $task = Task::factory()->in($workspace)->create();

    // A revoked member has no current workspace, so the scoped {task} binding never resolves.
    $this->actingAs($revoked)
        ->postJson("comment-probe/{$task->id}", ['body' => 'Looks right to me'])
        ->assertNotFound();

    $this->actingAs($revoked)
        ->postJson("comment-probe/any/{$task->id}", ['body' => 'Looks right to me'])
        ->assertForbidden();
});

it('refuses somebody from another workspace', function (): void {
    [$workspace] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $stranger = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    $this->actingAs($stranger)
        ->postJson("comment-probe/{$task->id}", ['body' => 'Looks right to me'])
        ->assertNotFound();

    $this->actingAs($stranger)
        ->postJson("comment-probe/any/{$task->id}", ['body' => 'Looks right to me'])
        ->assertForbidden();
});

it('reads its subject from the route even when that subject is outside the current workspace', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $elsewhere = Workspace::factory()->create();
    memberOf($elsewhere, WorkspaceRole::Member, WorkspaceMembershipStatus::Revoked, user: $actor);
    $actor->forceFill(['current_workspace_id' => $elsewhere->id])->save();

    $this->actingAs($actor)
        ->postJson("comment-probe/any/{$task->id}", ['body' => 'Looks right to me'])
        ->assertOk()
        ->assertJson(['subject' => $task->id]);
});
