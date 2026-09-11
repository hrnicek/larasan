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
 * One thread out of two tables.
 *
 * Comments and activities are interleaved by time in the **database**, not in PHP: merging two
 * paginated lists after the fact gives a page that is neither table's page, and a thread that
 * skips lines as soon as it is longer than one screen.
 *
 * Newest first, because a long thread is read from its end and "load older" is the direction
 * people actually scroll. The screen reverses a page to draw it.
 *
 * The workspace is proven here rather than assumed from the subject: both tables carry
 * `workspace_id`, and asking for it costs nothing next to the alternative of a feed that
 * trusts whatever id it was handed (ADR-0005).
 *
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

        /*
         * Asked once for the page rather than per line. Reach is already settled — somebody
         * reading this feed can read the task — so what is left of `CommentPolicy` is
         * authorship and this one capability (`CommentPolicyTest` and `TaskFeedQueryTest` both
         * assert the two answers agree).
         */
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
        /*
         * Deleted comments stay in the thread. The feed says a line was removed rather than
         * closing the gap, which would change what the conversation appears to say — and the
         * body is dropped below, so "removed" is not a place the words are still readable.
         */
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

        // `id` as the tiebreaker: `created_at` is `timestamp(0)`, and two lines in the same
        // second would otherwise be paginated in whichever order PostgreSQL chose that day —
        // which is how a page repeats one line and drops another.
        return DB::query()
            ->fromSub($comments->unionAll($activities), 'feed')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Every actor on the page in one read. A feed is the one screen where each line has a
     * different person on it, so a lazy relation here is an N+1 per page rather than per
     * screen.
     *
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
     * The name each person mentioned on this page goes by now, in one read.
     *
     * Only live members of this workspace are looked up. A token is text a request wrote, and
     * resolving its id against every account would let a crafted one read a stranger's name — so
     * a token naming anybody else keeps the name it was written with.
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
            /*
             * ISO 8601 rather than the database's own `Y-m-d H:i:s`. The screen draws this in the
             * reader's locale and time zone, and only one of the two formats can be parsed the
             * same way by every browser.
             */
            'createdAt' => Carbon::parse($line->created_at)->toIso8601String(),
            /*
             * Null where the account is gone rather than a placeholder name: the row survives
             * its author on purpose, and inventing "Deleted user" here would put a name in the
             * feed that nobody can look up.
             */
            'actor' => PersonSummary::fromNullable($actor),
            // The words of a removed comment are not readable through the feed that reports it
            // as removed. A mention reads with the name the person has today.
            'body' => $deleted || $line->body === null ? null : Mentions::withNames($line->body, $names),
            'edited' => $line->edited_at !== null,
            'deleted' => $deleted,
            'type' => $line->type,
            'properties' => $line->properties === null
                ? null
                : json_decode((string) $line->properties, true, 512, JSON_THROW_ON_ERROR),
            /*
             * The permissions the UI renders come from here, never from a rule written into a
             * template. An activity is nobody's to change: it is a record of something that
             * already happened.
             */
            'canEdit' => $isComment && $isAuthor && ! $deleted,
            'canDelete' => $isComment && ! $deleted && ($isAuthor || $canModerate),
        ];
    }
}
