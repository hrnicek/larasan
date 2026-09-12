<?php

declare(strict_types=1);

use App\Domain\Comment\Models\Comment;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Facades\DB;

it('writes a comment about a task', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->post(route('tasks.comments.store', $task), ['body' => '  Looks right to me  '])
        ->assertRedirect(route('tasks.show', $task));

    expect($task->comments()->pluck('body')->all())->toBe(['Looks right to me']);
});

it('edits a comment and marks it edited', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $comment = Comment::factory()->on($task)->by($actor)->create(['body' => 'First thought']);

    $this->actingAs($actor)
        ->put(route('comments.update', $comment), ['body' => 'Second thought'])
        ->assertRedirect();

    $comment->refresh();

    expect($comment->body)->toBe('Second thought')
        ->and($comment->isEdited())->toBeTrue();
});

it('removes a comment without removing the row', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $comment = Comment::factory()->on($task)->by($actor)->create();

    $this->actingAs($actor)
        ->delete(route('comments.destroy', $comment))
        ->assertRedirect();

    expect($task->comments()->count())->toBe(0)
        ->and(DB::table('comments')->where('id', $comment->id)->whereNotNull('deleted_at')->exists())->toBeTrue();
});

it('refuses an edit of somebody else s comment', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $comment = Comment::factory()->on($task)->create(['body' => 'First thought']);

    $this->actingAs($actor)
        ->put(route('comments.update', $comment), ['body' => 'Rewritten'])
        ->assertForbidden();

    expect($comment->fresh()?->body)->toBe('First thought');
});

it('refuses a delete by a guest who did not write it', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();
    $comment = Comment::factory()->on($task)->create();

    $this->actingAs($guest)
        ->delete(route('comments.destroy', $comment))
        ->assertForbidden();

    expect($comment->fresh()?->deleted_at)->toBeNull();
});

it('hides a comment in another workspace behind a 404', function (): void {
    $comment = Comment::factory()->create();
    $stranger = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    $this->actingAs($stranger)->put(route('comments.update', $comment), ['body' => 'Rewritten'])->assertNotFound();
    $this->actingAs($stranger)->delete(route('comments.destroy', $comment))->assertNotFound();
});

it('cannot address a comment that has already been removed', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $comment = Comment::factory()->on($task)->by($actor)->create();
    $comment->delete();

    $this->actingAs($actor)->put(route('comments.update', $comment), ['body' => 'Rewritten'])->assertNotFound();
    $this->actingAs($actor)->delete(route('comments.destroy', $comment))->assertNotFound();
});

it('refuses commenting on a task in a project the actor was not given', function (): void {
    $workspace = Workspace::factory()->create();
    $outsider = memberOf($workspace, WorkspaceRole::Member);
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();

    $this->actingAs($outsider)
        ->post(route('tasks.comments.store', $task), ['body' => 'Looks right to me'])
        ->assertForbidden();

    expect(Comment::query()->count())->toBe(0);
});

it('turns away everybody who is not signed in', function (): void {
    $comment = Comment::factory()->create();
    $task = Task::factory()->create();

    $this->post(route('tasks.comments.store', $task), ['body' => 'Hello'])->assertRedirect(route('login'));
    $this->put(route('comments.update', $comment), ['body' => 'Hello'])->assertRedirect(route('login'));
    $this->delete(route('comments.destroy', $comment))->assertRedirect(route('login'));
});
