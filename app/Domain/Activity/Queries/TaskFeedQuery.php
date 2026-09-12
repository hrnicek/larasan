<?php

declare(strict_types=1);

namespace App\Domain\Activity\Queries;

use App\Domain\Comment\Support\Mentions;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Payloads\PersonSummary;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type FeedLine object{
 *     id: string,
 *     kind: string,
 *     created_at: string,
 *     actor_id: int|null,
 *     body: string|null,
 *     edited_at: string|null,
 *     deleted_at: string|null,
 *     type: string|null,
 *     properties: string|null,
 * }
 */
final readonly class TaskFeedQuery
{
    public const PER_PAGE = 30;

    /**
     * @return array{
     *     entries: list<array<string, mixed>>,
     *     meta: array{page: int, perPage: int, total: int, hasMore: bool},
     * }
     */
    public function __invoke(Task $task, User $viewer, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $lines = $this->paginate($task, $page, $perPage);

        /** @var list<FeedLine> $rows */
        $rows = array_values($lines->items());

        $actors = $this->actors($rows);
        $names = $this->mentionedNames($task, $rows);

        $canModerate = $task->workspace->membershipFor($viewer)?->allows(Capability::CommentDelete) === true;

        return [
            'entries' => array_map(fn (object $line): array => $this->entry($line, $actors, $names, $viewer, $canModerate), $rows),
            'meta' => [
                'page' => $lines->currentPage(),
                'perPage' => $lines->perPage(),
                'total' => $lines->total(),
                'hasMore' => $lines->hasMorePages(),
            ],
        ];
    }

    /**
     * @return LengthAwarePaginator<int, FeedLine>
     */
    private function paginate(Task $task, int $page, int $perPage): LengthAwarePaginator
    {
        $comments = DB::table('comments')
            ->where('workspace_id', $task->workspace_id)
            ->where('commentable_type', 'task')
            ->where('commentable_id', $task->id)
            ->selectRaw(<<<'SQL'
                id,
                'comment' as kind,
                created_at,
                author_id as actor_id,
                body,
                edited_at,
                deleted_at,
                null::varchar as type,
                null::jsonb as properties
            SQL);

        $activities = DB::table('activities')
            ->where('workspace_id', $task->workspace_id)
            ->where('subject_type', 'task')
            ->where('subject_id', $task->id)
            ->selectRaw(<<<'SQL'
                id,
                'activity' as kind,
                created_at,
                actor_id,
                null::text as body,
                null::timestamp as edited_at,
                null::timestamp as deleted_at,
                type,
                properties::jsonb
            SQL);

        // `created_at` is `timestamp(0)`, so `id` breaks ties to keep pagination stable.
        return DB::query()
            ->fromSub($comments->unionAll($activities), 'feed')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, page: $page);
    }

    /**
     * @param  list<FeedLine>  $lines
     * @return Collection<int, User>
     */
    private function actors(array $lines): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn (object $line): ?int => $line->actor_id,
            $lines,
        ))));

        if ($ids === []) {
            return collect();
        }

        return User::query()->whereIn('id', $ids)->get(PersonSummary::columns())->keyBy('id');
    }

    /**
     * Resolved against workspace members only, so a crafted mention token cannot reveal a stranger's name.
     *
     * @param  list<FeedLine>  $lines
     * @return array<int, string>
     */
    private function mentionedNames(Task $task, array $lines): array
    {
        $ids = array_values(array_unique(array_merge(...array_map(
            fn (object $line): array => $line->body === null || $line->deleted_at !== null ? [] : Mentions::idsIn($line->body),
            $lines,
        ))));

        if ($ids === []) {
            return [];
        }

        /** @var array<int, string> $names */
        $names = $task->workspace->members()->whereKey($ids)->pluck('users.name', 'users.id')->all();

        return $names;
    }

    /**
     * @param  FeedLine  $line
     * @param  Collection<int, User>  $actors
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function entry(object $line, Collection $actors, array $names, User $viewer, bool $canModerate): array
    {
        $actor = $actors->get($line->actor_id);
        $deleted = $line->deleted_at !== null;
        $isComment = $line->kind === 'comment';
        $isAuthor = $isComment && $line->actor_id !== null && $line->actor_id === $viewer->id;

        return [
            'id' => (string) $line->id,
            'kind' => (string) $line->kind,
            'createdAt' => Carbon::parse($line->created_at)->toIso8601String(),
            'actor' => PersonSummary::fromNullable($actor),
            'body' => $deleted || $line->body === null ? null : Mentions::withNames($line->body, $names),
            'edited' => $line->edited_at !== null,
            'deleted' => $deleted,
            'type' => $line->type,
            'properties' => $line->properties === null
                ? null
                : json_decode((string) $line->properties, true, 512, JSON_THROW_ON_ERROR),
            'canEdit' => $isComment && $isAuthor && ! $deleted,
            'canDelete' => $isComment && ! $deleted && ($isAuthor || $canModerate),
        ];
    }
}
