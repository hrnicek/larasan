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

/**
 * The endpoints arrive with TASK-110-005. A probe route asserts the request for what it is —
 * validation and authorization — before a controller exists to confuse a failure with a
 * routing one.
 */
beforeEach(function (): void {
    Route::middleware('web')->post('comment-probe/{task}', fn (StoreCommentRequest $request, Task $task) => response()->json([
        'body' => CreateCommentData::fromRequest($request)->body,
        'subject' => $request->subject()?->getKey(),
    ]));

    /*
     * The same request behind an **unscoped** binding. `{task}` resolves inside the current
     * workspace (routes/tasks.php), which means the real route can never present a subject
     * from somewhere else — and would therefore never notice a request that read the
     * capability from the workspace the actor happens to be in.
     */
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

    // A request cannot talk itself into another subject: `commentable_id` in the body is not
    // where the subject comes from, and the answer stays the bound task.
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

    // Prose, not a document: long enough for a paragraph of reasoning, short enough that the
    // feed stays readable and one row stays bounded.
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

    // The capability alone is not enough, in the request for the same reason it is not enough
    // in the Action (TASK-110-003).
    $this->actingAs($outsider)
        ->postJson("comment-probe/{$task->id}", ['body' => 'Looks right to me'])
        ->assertForbidden();
});

it('refuses somebody whose membership is no longer live', function (): void {
    $workspace = Workspace::factory()->create();
    $revoked = memberOf($workspace, WorkspaceRole::Member, WorkspaceMembershipStatus::Revoked);
    $task = Task::factory()->in($workspace)->create();

    // A 404 rather than a 403: a revoked member has no current workspace, so `{task}` never
    // resolves and the request is not reached at all (routes/tasks.php).
    $this->actingAs($revoked)
        ->postJson("comment-probe/{$task->id}", ['body' => 'Looks right to me'])
        ->assertNotFound();

    // The request refuses them on its own terms too, where the binding cannot.
    $this->actingAs($revoked)
        ->postJson("comment-probe/any/{$task->id}", ['body' => 'Looks right to me'])
        ->assertForbidden();
});

it('refuses somebody from another workspace', function (): void {
    [$workspace] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $stranger = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    // Another tenant's task is not theirs to know about, so the binding answers first.
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

    /*
     * The subject decides the workspace, so the request answers about the task in front of it
     * rather than about wherever the actor was last standing.
     *
     * That the *capability* is read from the subject's workspace cannot be shown from here:
     * every role grants `comment.create`, and the current workspace falls back to a live
     * membership, so both readings agree on every reachable input. The attribution is pinned
     * where it is observable — `CreateCommentTest`, mutation-checked.
     */
    $elsewhere = Workspace::factory()->create();
    memberOf($elsewhere, WorkspaceRole::Member, WorkspaceMembershipStatus::Revoked, user: $actor);
    $actor->forceFill(['current_workspace_id' => $elsewhere->id])->save();

    $this->actingAs($actor)
        ->postJson("comment-probe/any/{$task->id}", ['body' => 'Looks right to me'])
        ->assertOk()
        ->assertJson(['subject' => $task->id]);
});
