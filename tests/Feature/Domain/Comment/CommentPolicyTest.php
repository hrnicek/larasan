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
    // Domain policies live outside the framework's default discovery layout.
    expect(Gate::getPolicyFor(Comment::class))->toBeInstanceOf(CommentPolicy::class);
});

it('answers reading a comment by asking about its subject', function (): void {
    $workspace = Workspace::factory()->create();
    $reader = memberOf($workspace, WorkspaceRole::Member);
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();
    $comment = Comment::factory()->on($task)->create();

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

    expect(Gate::forUser($author)->allows('update', $comment))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $comment))->toBeFalse();
});

it('lets the author or a moderator delete, and nobody else', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $author = memberOf($workspace, WorkspaceRole::Member);
    $comment = Comment::factory()->on($task)->by($author)->create();

    // comment.delete is held by every full member but not by guests. See ADR-0010.
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

it('stops an author editing once they may no longer comment on the subject', function (): void {
    $workspace = Workspace::factory()->create();
    $author = memberOf($workspace, WorkspaceRole::Member);
    $project = Project::factory()->in($workspace)->private()->create();
    $membership = ProjectMembership::factory()->in($project)->forUser($author)->withAccess(ProjectAccessLevel::Commenter)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();
    $comment = Comment::factory()->on($task)->by($author)->create();

    expect(Gate::forUser($author)->allows('update', $comment))->toBeTrue();

    $membership->forceFill(['access_level' => ProjectAccessLevel::Viewer])->save();

    expect(Gate::forUser($author->fresh())->allows('view', $comment))->toBeTrue()
        ->and(Gate::forUser($author->fresh())->allows('update', $comment))->toBeFalse();
});

it('stops an author editing on a project that has been archived', function (): void {
    $workspace = Workspace::factory()->create();
    $author = memberOf($workspace, WorkspaceRole::Member);
    $project = Project::factory()->in($workspace)->archived()->create();
    ProjectMembership::factory()->in($project)->forUser($author)->withAccess(ProjectAccessLevel::Editor)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();
    $comment = Comment::factory()->on($task)->by($author)->create();

    expect(Gate::forUser($author)->allows('view', $comment))->toBeTrue()
        ->and(Gate::forUser($author)->allows('update', $comment))->toBeFalse();
});
