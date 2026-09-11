<?php

declare(strict_types=1);

namespace App\Domain\Notification\Queries;

use App\Domain\Comment\Models\Comment;
use App\Domain\Notification\Notifications\CommentPostedNotification;
use App\Domain\Notification\Notifications\MentionedInCommentNotification;
use App\Domain\Notification\Notifications\TaskAssignedNotification;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Shared\Payloads\PersonSummary;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What is waiting for one person, here.
 *
 * Scoped to the workspace as well as to the account: the Inbox is per workspace
 * (`docs/ui/inbox.md`), and somebody who belongs to three of them should not have to read
 * three inboxes at once to find the thing they were told about.
 *
 * A row is rendered from **ids the notification kept**, resolved now — never from a snapshot of
 * names taken when it was written. A notification read a week later has to say what the task is
 * called today, not what it was called then.
 *
 * @phpstan-type InboxActor array{id: int, name: string, email: string}
 */
final readonly class InboxQuery
{
    public const PER_PAGE = 25;

    /** Enough to recognise the comment by; the rest is one click away. */
    private const EXCERPT_LENGTH = 160;

    public function __construct(private VisibleProjectsForUser $visibleProjects) {}

    /**
     * @return array{
     *     notifications: list<array<string, mixed>>,
     *     meta: array{page: int, perPage: int, total: int, hasMore: bool, unread: int},
     * }
     */
    public function __invoke(Workspace $workspace, User $reader, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $notifications = $this->paginate($workspace, $reader, $page, $perPage);

        /** @var Collection<int, DatabaseNotification> $rows */
        $rows = $notifications->getCollection();

        $actors = $this->actors($rows);
        $subjects = $this->subjects($rows, $workspace, $reader);
        $excerpts = $this->excerpts($rows, $subjects, $workspace, $reader);

        return [
            'notifications' => array_values($rows
                ->map(fn (DatabaseNotification $notification): array => $this->row($notification, $actors, $subjects, $excerpts, $workspace, $reader))
                ->all()),
            'meta' => [
                'page' => $notifications->currentPage(),
                'perPage' => $notifications->perPage(),
                'total' => $notifications->total(),
                'hasMore' => $notifications->hasMorePages(),
                'unread' => $this->unreadCount($workspace, $reader),
            ],
        ];
    }

    /**
     * The badge's number, and the Inbox's own. One query, on the index TASK-110-014 built for
     * exactly this read.
     */
    public function unreadCount(Workspace $workspace, User $reader): int
    {
        return $this->scoped($workspace, $reader)->whereNull('read_at')->count();
    }

    /**
     * @return LengthAwarePaginator<int, DatabaseNotification>
     */
    private function paginate(Workspace $workspace, User $reader, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->scoped($workspace, $reader)
            // Unread first, then newest. `id` breaks the tie because `created_at` is
            // `timestamp(0)` and two notifications in one second would otherwise page unstably.
            ->orderByRaw('read_at is not null')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, page: $page);
    }

    /**
     * @return Builder<DatabaseNotification>
     */
    private function scoped(Workspace $workspace, User $reader): Builder
    {
        return DatabaseNotification::query()
            ->where('workspace_id', $workspace->id)
            ->where('notifiable_type', 'user')
            ->where('notifiable_id', $reader->id);
    }

    /**
     * Everybody who caused a line on this page, in one read.
     *
     * @param  Collection<int, DatabaseNotification>  $rows
     * @return Collection<int, User>
     */
    private function actors(Collection $rows): Collection
    {
        $ids = $rows
            ->map(fn (DatabaseNotification $notification): ?int => $this->actorId($notification))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('id', $ids)->get(PersonSummary::columns())->keyBy('id');
    }

    /**
     * Every subject on this page, in one read. A feed of notifications is the screen where a
     * lazy relation per row is at its worst: each one points somewhere different.
     *
     * @param  Collection<int, DatabaseNotification>  $rows
     * @return Collection<string, Task>
     */
    private function subjects(Collection $rows, Workspace $workspace, User $reader): Collection
    {
        $ids = $rows
            ->map(fn (DatabaseNotification $notification): ?string => $this->taskId($notification))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $visible = $this->visibleProjects
            ->query($workspace, $reader, includeArchived: true)
            ->select('projects.id');

        /*
         * Reach, asked for the whole page in one read rather than per row through the policy.
         * The two counts are `TaskPolicy::view()` written as arithmetic: a task is reachable if
         * it appears in a project the reader can open, or if it appears in no project at all and
         * the reader is not a guest — guests hold projects, and a task in none was never given
         * to them.
         */
        return Task::query()
            ->whereIn('id', $ids)
            ->withCount([
                'placements',
                'placements as reachable_placements_count' => fn (Builder $placements) => $placements->whereIn('project_id', $visible),
            ])
            ->with([
                // Only the projects this reader can open: a task can live in one they were never
                // given, and naming it on their inbox would leak it through the task.
                'projects' => fn (Relation $projects) => $projects
                    ->whereIn('projects.id', $visible)
                    ->select(['projects.id', 'projects.name', 'projects.color']),
            ])
            ->get(['id', 'workspace_id', 'title'])
            ->keyBy('id');
    }

    /**
     * What each comment on this page said, in one read — the line under the sentence, so a
     * comment can be triaged without opening its task.
     *
     * Only for tasks this reader can still reach: the words are the task's, and somebody who
     * lost the project lost them too.
     *
     * @param  Collection<int, DatabaseNotification>  $rows
     * @param  Collection<string, Task>  $subjects
     * @return Collection<string, string>
     */
    private function excerpts(Collection $rows, Collection $subjects, Workspace $workspace, User $reader): Collection
    {
        $ids = $rows
            ->filter(function (DatabaseNotification $notification) use ($subjects, $workspace, $reader): bool {
                $taskId = $this->taskId($notification);
                $task = $taskId === null ? null : $subjects->get($taskId);

                return $task !== null && $this->reaches($task, $workspace, $reader);
            })
            ->map(fn (DatabaseNotification $notification): ?string => $this->commentId($notification))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Comment::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $ids)
            ->get(['id', 'body'])
            ->mapWithKeys(fn (Comment $comment): array => [
                (string) $comment->id => Str::limit(Str::squish($comment->body), self::EXCERPT_LENGTH),
            ]);
    }

    /**
     * @param  Collection<int, User>  $actors
     * @param  Collection<string, Task>  $subjects
     * @param  Collection<string, string>  $excerpts
     * @return array<string, mixed>
     */
    private function row(DatabaseNotification $notification, Collection $actors, Collection $subjects, Collection $excerpts, Workspace $workspace, User $reader): array
    {
        $actorId = $this->actorId($notification);
        $actor = $actorId === null ? null : $actors->get($actorId);
        $taskId = $this->taskId($notification);
        $task = $taskId === null ? null : $subjects->get($taskId);
        $commentId = $this->commentId($notification);

        return [
            'id' => $notification->id,
            'type' => $this->shortType($notification),
            'createdAt' => $notification->created_at?->toIso8601String(),
            'readAt' => $notification->read_at?->toIso8601String(),
            'read' => $notification->read_at !== null,
            /*
             * Null where the account is gone rather than an invented name: a notification
             * outlives nothing here — it is deleted with its reader — but the person who caused
             * it may well have left.
             */
            'actor' => PersonSummary::fromNullable($actor),
            // What was said, as it reads now. Null for a line that is not a comment, a comment
            // since deleted, or a task this reader can no longer reach.
            'excerpt' => $commentId === null ? null : $excerpts->get($commentId),
            /*
             * Resolved now, so the line says what the task is called today. Null when the task
             * has since been deleted — a notification outlives what it points at, and the screen
             * says so rather than linking nowhere.
             */
            'subject' => $task === null ? null : [
                'type' => 'task',
                'id' => $task->id,
                'title' => $task->title,
                /*
                 * The address, or null where this reader can no longer reach it. Somebody can be
                 * told about a task and then lose the project it lives in; a link they cannot
                 * follow is worse than a sentence they can still read.
                 */
                'url' => $this->reaches($task, $workspace, $reader) ? route('tasks.show', $task->id) : null,
                'projects' => array_values($task->projects
                    ->map(fn (Project $project): array => [
                        'id' => $project->id,
                        'name' => $project->name,
                        'color' => $project->color?->value,
                    ])
                    ->all()),
            ],
        ];
    }

    /**
     * `TaskPolicy::view()`, answered from counts this query already fetched. Membership is not
     * asked again: a reader with none has no inbox here at all.
     */
    private function reaches(Task $task, Workspace $workspace, User $reader): bool
    {
        if ((int) ($task->reachable_placements_count ?? 0) > 0) {
            return true;
        }

        // The workspace in hand rather than the task's own relation: they are the same
        // workspace, and reading it from the task would be a query per row.
        return (int) ($task->placements_count ?? 0) === 0
            && $workspace->membershipFor($reader)?->role->isGuest() === false;
    }

    private function shortType(DatabaseNotification $notification): string
    {
        return match ($notification->type) {
            TaskAssignedNotification::class => 'task.assigned',
            CommentPostedNotification::class => 'comment.posted',
            MentionedInCommentNotification::class => 'comment.mentioned',
            // A class name is not something to show anybody, and a notification this query does
            // not know is still a line in somebody's inbox.
            default => 'unknown',
        };
    }

    private function actorId(DatabaseNotification $notification): ?int
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        $id = $data['assigned_by_id'] ?? $data['author_id'] ?? null;

        return is_int($id) ? $id : null;
    }

    private function taskId(DatabaseNotification $notification): ?string
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        $id = $data['task_id'] ?? null;

        return is_string($id) ? $id : null;
    }

    private function commentId(DatabaseNotification $notification): ?string
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        $id = $data['comment_id'] ?? null;

        return is_string($id) ? $id : null;
    }
}
