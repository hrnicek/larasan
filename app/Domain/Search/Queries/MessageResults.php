<?php

declare(strict_types=1);

namespace App\Domain\Search\Queries;

use App\Domain\Comment\Models\Comment;
use App\Domain\Shared\Html\RichText;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Queries\ReachableTasks;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The messages a term finds, for the palette.
 *
 * A comment has no reach of its own: it is readable exactly when the thing it was said on is
 * (ADR-0006). So the hydration query asks `ReachableTasks` about the subject, which means a
 * comment on a task in a private project is not returned even when the index holds it — and it
 * stops being returned the moment the project changes, without anything being reindexed.
 *
 * Tasks are the only thing that can be commented on today (`Commentable`), and the query says so
 * rather than assuming it: a second subject type would need its own reach, and answering with
 * the wrong one is how a leak gets written.
 */
final readonly class MessageResults
{
    /** @see TaskResults::CANDIDATES_PER_RESULT */
    private const CANDIDATES_PER_RESULT = 4;

    /** What a palette row can show of a message before it stops being a row. */
    private const EXCERPT = 160;

    public function __construct(private ReachableTasks $reachable) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(Workspace $workspace, User $actor, string $term, int $limit = 5): array
    {
        $term = trim($term);

        if ($term === '' || $limit < 1) {
            return [];
        }

        $results = Comment::search($term)
            ->where('workspace_id', $workspace->id)
            ->where('commentable_type', 'task')
            ->query(fn (Builder $comments): Builder => $comments
                ->where('commentable_type', 'task')
                ->whereIn('commentable_id', $this->reachable
                    ->constrain(Task::query(), $workspace, $actor)
                    ->select('tasks.id'))
                ->with(['author:id,name,email', 'commentable:id,title'])
                ->select(['id', 'workspace_id', 'commentable_id', 'commentable_type', 'author_id', 'body', 'created_at', 'edited_at']))
            ->take($limit * self::CANDIDATES_PER_RESULT)
            ->get()
            ->take($limit)
            ->map(fn (Comment $comment): array => $this->row($comment));

        return array_values($results->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Comment $comment): array
    {
        $author = $comment->author;
        $task = $comment->commentable;

        return [
            'id' => $comment->id,
            'excerpt' => Str::limit(RichText::toPlainText($comment->body), self::EXCERPT),
            'createdAt' => $comment->created_at?->toIso8601String(),
            'edited' => $comment->isEdited(),
            'author' => $author === null ? null : [
                'id' => $author->id,
                'name' => $author->name,
                'email' => $author->email,
            ],
            // The task is how a message is opened: a comment on its own is a line with no
            // context, and the palette row is that context.
            'task' => $task instanceof Task ? ['id' => $task->id, 'title' => $task->title] : null,
        ];
    }
}
