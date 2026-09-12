<?php

declare(strict_types=1);

use App\Domain\Comment\Actions\DeleteComment;
use App\Domain\Comment\Actions\UpdateComment;
use App\Domain\Comment\Data\UpdateCommentData;
use App\Domain\Comment\Events\CommentDeleted;
use App\Domain\Comment\Events\CommentEdited;
use App\Domain\Comment\Exceptions\CommentException;
use App\Domain\Comment\Models\Comment;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * @return array{Comment, User, Workspace, Task}
 */
function threadWithAComment(WorkspaceRole $role = WorkspaceRole::Member): array
{
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $author = memberOf($workspace, $role);
    $comment = Comment::factory()->on($task)->by($author)->create(['body' => 'First thought']);

    return [$comment, $author, $workspace, $task];
}

function editComment(Comment $comment, User $actor, string $body): Comment
{
    return app(UpdateComment::class)->handle($comment, $actor, new UpdateCommentData(body: $body));
}

it('changes what was said and says that it changed', function (): void {
    [$comment, $author] = threadWithAComment();

    $edited = editComment($comment, $author, '  Second thought  ');

    expect($edited->body)->toBe('Second thought')
        ->and($edited->isEdited())->toBeTrue();
});

it('treats re-saving the same words as no edit at all', function (): void {
    [$comment, $author] = threadWithAComment();

    Event::fake();
    $result = editComment($comment, $author, 'First thought');

    expect($result->isEdited())->toBeFalse();
    Event::assertNotDispatched(CommentEdited::class);
});

it('refuses an edit with nothing in it', function (): void {
    [$comment, $author] = threadWithAComment();

    expect(fn (): Comment => editComment($comment, $author, "  \n "))
        ->toThrow(CommentException::class, 'A comment needs something in it.');

    expect($comment->fresh()?->body)->toBe('First thought');
});

it('refuses an edit by anybody but the author', function (): void {
    [$comment, , $workspace] = threadWithAComment();
    $owner = memberOf($workspace, WorkspaceRole::Owner);

    expect(fn (): Comment => editComment($comment, $owner, 'Rewritten'))
        ->toThrow(CommentException::class, 'Only the author can edit a comment.');

    expect($comment->fresh()?->body)->toBe('First thought');
});

it('refuses an edit by an author whose membership is no longer live', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $author = memberOf($workspace, WorkspaceRole::Member, WorkspaceMembershipStatus::Revoked);
    $comment = Comment::factory()->on($task)->create(['author_id' => $author->id, 'body' => 'First thought']);

    expect(fn (): Comment => editComment($comment, $author, 'Rewritten'))->toThrow(CommentException::class);
});

it('announces an edit', function (): void {
    [$comment, $author, $workspace, $task] = threadWithAComment();

    Event::fake();
    editComment($comment, $author, 'Second thought');

    Event::assertDispatched(CommentEdited::class, fn (CommentEdited $event): bool => $event->commentId === $comment->id
        && $event->workspaceId === $workspace->id
        && $event->subjectType === 'task'
        && $event->subjectId === $task->id
        && $event->editorId === $author->id);
});

it('removes a comment from the thread and keeps the row', function (): void {
    [$comment, $author, , $task] = threadWithAComment();

    app(DeleteComment::class)->handle($comment, $author);

    expect($task->comments()->count())->toBe(0)
        ->and(DB::table('comments')->where('id', $comment->id)->whereNotNull('deleted_at')->exists())->toBeTrue();
});

it('lets a moderator delete somebody else s comment', function (): void {
    [$comment, , $workspace] = threadWithAComment();
    $admin = memberOf($workspace, WorkspaceRole::Admin);

    Event::fake();
    app(DeleteComment::class)->handle($comment, $admin);

    expect($comment->fresh()?->deleted_at)->not->toBeNull();
    Event::assertDispatched(CommentDeleted::class, fn (CommentDeleted $event): bool => $event->actorId === $admin->id);
});

it('refuses a delete by somebody who holds neither authorship nor the capability', function (): void {
    [$comment, , $workspace] = threadWithAComment();
    $guest = memberOf($workspace, WorkspaceRole::Guest);

    expect(fn () => app(DeleteComment::class)->handle($comment, $guest))
        ->toThrow(CommentException::class, 'You do not have permission to delete this comment.');

    expect($comment->fresh()?->deleted_at)->toBeNull();
});

it('refuses to delete a comment twice', function (): void {
    [$comment, $author] = threadWithAComment();
    app(DeleteComment::class)->handle($comment, $author);

    expect(fn () => app(DeleteComment::class)->handle($comment, $author))->toThrow(CommentException::class);
});

it('refuses an edit of a deleted comment', function (): void {
    [$comment, $author] = threadWithAComment();
    app(DeleteComment::class)->handle($comment, $author);

    expect(fn (): Comment => editComment($comment, $author, 'Second thought'))->toThrow(CommentException::class);
});
