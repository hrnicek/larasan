<?php

declare(strict_types=1);

namespace App\Domain\Notification\Queries;

use App\Domain\Notification\Notifications\CommentPostedNotification;
use App\Domain\Notification\Notifications\TaskAssignedNotification;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

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
        $subjects = $this->subjects($rows);

        return [
            'notifications' => array_values($rows
                ->map(fn (DatabaseNotification $notification): array => $this->row($notification, $actors, $subjects))
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

        return User::query()->whereIn('id', $ids)->get(['id', 'name', 'email'])->keyBy('id');
    }

    /**
     * Every subject on this page, in one read. A feed of notifications is the screen where a
     * lazy relation per row is at its worst: each one points somewhere different.
     *
     * @param  Collection<int, DatabaseNotification>  $rows
     * @return Collection<string, Task>
     */
    private function subjects(Collection $rows): Collection
    {
        $ids = $rows
            ->map(fn (DatabaseNotification $notification): ?string => $this->taskId($notification))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Task::query()->whereIn('id', $ids)->get(['id', 'workspace_id', 'title'])->keyBy('id');
    }

    /**
     * @param  Collection<int, User>  $actors
     * @param  Collection<string, Task>  $subjects
     * @return array<string, mixed>
     */
    private function row(DatabaseNotification $notification, Collection $actors, Collection $subjects): array
    {
        $actorId = $this->actorId($notification);
        $actor = $actorId === null ? null : $actors->get($actorId);
        $taskId = $this->taskId($notification);
        $task = $taskId === null ? null : $subjects->get($taskId);

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
            'actor' => $actor === null ? null : [
                'id' => $actor->id,
                'name' => $actor->name,
                'email' => $actor->email,
            ],
            // Resolved now, so the line says what the task is called today. A subject that has
            // since been deleted is null, and TASK-130-008 decides how that renders.
            'subject' => $task === null ? null : [
                'type' => 'task',
                'id' => $task->id,
                'title' => $task->title,
            ],
        ];
    }

    private function shortType(DatabaseNotification $notification): string
    {
        return match ($notification->type) {
            TaskAssignedNotification::class => 'task.assigned',
            CommentPostedNotification::class => 'comment.posted',
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
}
