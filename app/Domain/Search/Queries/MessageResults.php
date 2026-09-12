<?php

declare(strict_types=1);

namespace App\Domain\Search\Queries;

use App\Domain\Comment\Models\Comment;
use App\Domain\Comment\Support\Mentions;
use App\Domain\Shared\Html\RichText;
use App\Domain\Shared\Payloads\PersonSummary;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Queries\ReachableTasks;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Only task comments are searched; another commentable type would need its own reach constraint. See ADR-0006.
 */
final readonly class MessageResults
{
    /** @see TaskResults::CANDIDATES_PER_RESULT */
    private const CANDIDATES_PER_RESULT = 4;

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

        $people = PersonSummary::for($workspace, $actor);

        $results = Comment::search($term)
            ->where('workspace_id', $workspace->id)
            ->where('commentable_type', 'task')
            ->query(fn (Builder $comments): Builder => $comments
                ->where('commentable_type', 'task')
                ->whereIn('commentable_id', $this->reachable
                    ->constrain(Task::query(), $workspace, $actor)
                    ->select('tasks.id'))
                ->with([PersonSummary::eager('author'), 'commentable:id,title'])
                ->select(['id', 'workspace_id', 'commentable_id', 'commentable_type', 'author_id', 'body', 'created_at', 'edited_at']))
            ->take($limit * self::CANDIDATES_PER_RESULT)
            ->get()
            ->take($limit)
            ->map(fn (Comment $comment): array => $this->row($comment, $people));

        return array_values($results->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Comment $comment, PersonSummary $people): array
    {
        $task = $comment->commentable;

        return [
            'id' => $comment->id,
            'excerpt' => Str::limit(RichText::toPlainText(Mentions::toPlainText($comment->body)), self::EXCERPT),
            'createdAt' => $comment->created_at?->toIso8601String(),
            'edited' => $comment->isEdited(),
            'author' => $people->ofNullable($comment->author),
            'task' => $task instanceof Task ? ['id' => $task->id, 'title' => $task->title] : null,
        ];
    }
}
