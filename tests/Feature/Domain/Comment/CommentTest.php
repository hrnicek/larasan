<?php

declare(strict_types=1);

use App\Domain\Comment\Models\Comment;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

it('hangs from its subject and its author', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $author = memberOf($workspace);

    $comment = Comment::factory()->on($task)->by($author)->create();

    expect($comment->commentable->is($task))->toBeTrue()
        ->and($comment->author?->is($author))->toBeTrue()
        ->and($comment->workspace_id)->toBe($workspace->id);
});

it('reads a task s comments in the order they were said', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();

    foreach (['First', 'Second', 'Third'] as $body) {
        Comment::factory()->on($task)->create(['body' => $body]);
    }

    // Oldest first, then by key: `created_at` is `timestamp(0)`, so two comments in the same
    // second would otherwise come back in whichever order PostgreSQL chose that day.
    expect($task->comments()->pluck('body')->all())->toBe(['First', 'Second', 'Third']);
});

it('keeps another subject s comments out', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $other = Task::factory()->in($workspace)->create();

    Comment::factory()->on($task)->create(['body' => 'Mine']);
    Comment::factory()->on($other)->create(['body' => 'Theirs']);

    expect($task->comments()->pluck('body')->all())->toBe(['Mine']);
});

it('writes a short name into the type column, never a class name', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();

    $comment = Comment::factory()->on($task)->create();

    /*
     * A class name in a database column is a rename waiting to break a table. The map is
     * enforced, so an unmapped model is an error when somebody writes one rather than a row
     * nobody can read back.
     */
    expect(DB::table('comments')->where('id', $comment->id)->value('commentable_type'))->toBe('task')
        ->and(Relation::getMorphedModel('task'))->toBe(Task::class);
});

it('refuses to have its subject or author mass assigned', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();

    // The workspace, the subject and the author are decided by the Action from who is asking
    // and what they are asking about, never by a payload.
    expect(fn (): Comment => (new Comment)->fill([
        'body' => 'Fine',
        'commentable_id' => $task->id,
        'author_id' => 1,
    ]))->toThrow(MassAssignmentException::class);
});

it('hides a soft-deleted comment from the thread and keeps the row', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $comment = Comment::factory()->on($task)->create();

    $comment->delete();

    // The row survives so the feed can say a comment was removed rather than closing the gap
    // and changing what the conversation appears to say (TASK-110-006).
    expect($task->comments()->count())->toBe(0)
        ->and(DB::table('comments')->where('id', $comment->id)->exists())->toBeTrue();
});

it('says whether it has been edited', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();

    expect(Comment::factory()->on($task)->create()->isEdited())->toBeFalse()
        ->and(Comment::factory()->on($task)->edited()->create()->isEdited())->toBeTrue();
});

it('gives a factory comment an author who is actually in the workspace', function (): void {
    $comment = Comment::factory()->create();

    // A factory that built a comment by a stranger would hand every later test a row the
    // domain would have refused to create.
    expect($comment->workspace->hasActiveMember((int) $comment->author_id))->toBeTrue();
});
