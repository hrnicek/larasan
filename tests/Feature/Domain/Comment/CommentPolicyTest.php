<?php

declare(strict_types=1);

use App\Domain\Comment\Models\Comment;
use App\Domain\Comment\Policies\CommentPolicy;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Gate;

it('is the policy the framework finds for a comment', function (): void {
    // The `app/Domain/<Context>/Policies` pairing is not the layout the framework documents,
    // so a namespace move would otherwise stop authorizing in silence.
    expect(Gate::getPolicyFor(Comment::class))->toBeInstanceOf(CommentPolicy::class);
});

it('answers reading a comment by asking about its subject', function (): void {
    $workspace = Workspace::factory()->create();
    $reader = memberOf($workspace, WorkspaceRole::Member);
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();
    $comment = Comment::factory()->on($task)->create();

    // Nothing about the comment changed — the task moved out of reach, and the conversation
    // about it went with it.
    expect(Gate::forUser($reader)->allows('view', $comment))->toBeFalse();

    ProjectMembership::factory()->in($private)->forUser($reader)->withAccess(ProjectAccessLevel::Viewer)->create();

    expect(Gate::forUser($reader->fresh())->allows('view', $comment))->toBeTrue();
});

it('lets only the author edit what they said', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $author = memberOf($workspace, WorkspaceRole::Member);
    $owner = memberOf($workspace, WorkspaceRole::Owner);
    $comment = Comment::factory()->on($task)->by($author)->create();

    /*
     * Not even the workspace owner. An administrator who could rewrite what somebody else said
     * would make the thread evidence of nothing — moderation removes a comment, it does not
     * reword it.
     */
    expect(Gate::forUser($author)->allows('update', $comment))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $comment))->toBeFalse();
});

it('lets the author or a moderator delete, and nobody else', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $author = memberOf($workspace, WorkspaceRole::Member);
    $comment = Comment::factory()->on($task)->by($author)->create();

    /*
     * `comment.delete` is every full member's, not only an administrator's (ADR-0010): a
     * workspace's members moderate their own workspace. A guest does not hold it, so a guest
     * may say something and — until TASK-110-006 gives them authorship of it — remove only
     * what they wrote themselves.
     */
    expect(Gate::forUser($author)->allows('delete', $comment))->toBeTrue()
        ->and(Gate::forUser(memberOf($workspace, WorkspaceRole::Admin))->allows('delete', $comment))->toBeTrue()
        ->and(Gate::forUser(memberOf($workspace, WorkspaceRole::Member))->allows('delete', $comment))->toBeTrue()
        ->and(Gate::forUser(memberOf($workspace, WorkspaceRole::Guest))->allows('delete', $comment))->toBeFalse();
});

it('refuses an author whose membership is no longer live', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $author = memberOf($workspace, WorkspaceRole::Member, WorkspaceMembershipStatus::Revoked);
    $comment = Comment::factory()->on($task)->create(['author_id' => $author->id]);

    // Authorship is not a way back into a workspace somebody was removed from.
    expect(Gate::forUser($author)->allows('view', $comment))->toBeFalse()
        ->and(Gate::forUser($author)->allows('update', $comment))->toBeFalse()
        ->and(Gate::forUser($author)->allows('delete', $comment))->toBeFalse();
});

it('refuses everything to somebody from another workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $comment = Comment::factory()->on($task)->create();
    $stranger = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    expect(Gate::forUser($stranger)->allows('view', $comment))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('update', $comment))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('delete', $comment))->toBeFalse();
});

it('leaves a deleted comment alone', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $author = memberOf($workspace, WorkspaceRole::Member);
    $comment = Comment::factory()->on($task)->by($author)->create();
    $comment->delete();

    // The row survives so the feed can say a comment was removed; editing or deleting it
    // again would be changing something that is no longer part of the conversation.
    expect(Gate::forUser($author)->allows('update', $comment))->toBeFalse()
        ->and(Gate::forUser($author)->allows('delete', $comment))->toBeFalse();
});

it('lets a guest who was given the project read the thread on it', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Viewer)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();
    $comment = Comment::factory()->on($task)->create();

    expect(Gate::forUser($guest)->allows('view', $comment))->toBeTrue();
});
