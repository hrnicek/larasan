<?php

declare(strict_types=1);

namespace App\Domain\Notification\Queries;

use App\Domain\Comment\Models\Comment;
use App\Domain\Comment\Support\Mentions;
use App\Domain\Notification\Notifications\CommentPostedNotification;
use App\Domain\Notification\Notifications\MentionedInCommentNotification;
use App\Domain\Notification\Notifications\TaskAssignedNotification;
use App\Domain\Notification\Notifications\TaskCollaboratorAddedNotification;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Shared\Payloads\PersonSummary;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Queries\ReachableTasks;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class InboxQuery
{
    public const PER_PAGE = 25;

    private const EXCERPT_LENGTH = 160;

    public function __construct(
        private VisibleProjectsForUser $visibleProjects,
        private ReachableTasks $reachableTasks,
    ) {}

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
        $excerpts = $this->excerpts($rows, $subjects, $workspace);
        $people = PersonSummary::for($workspace, $reader);

        return [
            'notifications' => array_values($rows
                ->map(fn (DatabaseNotification $notification): array => $this->row($notification, $actors, $subjects, $excerpts, $people))
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
            // Unread first; `id` breaks ties because `created_at` is `timestamp(0)`.
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

        $reachable = DB::query()
            ->fromSub($this->reachableTasks->idsFor($workspace, $reader)->whereIn('tasks.id', $ids), 'reach')
            ->whereColumn('reach.id', 'tasks.id')
            ->selectRaw('count(*) > 0');

        return Task::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $ids)
            ->select(['id', 'workspace_id', 'title'])
            ->selectSub($reachable, 'reachable')
            ->with([
                // Constrained so a project the reader cannot open is never named.
                'projects' => fn (Relation $projects) => $projects
                    ->whereIn('projects.id', $visible)
                    ->select(['projects.id', 'projects.name', 'projects.color']),
            ])
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, DatabaseNotification>  $rows
     * @param  Collection<string, Task>  $subjects
     * @return Collection<string, string>
     */
    private function excerpts(Collection $rows, Collection $subjects, Workspace $workspace): Collection
    {
        $ids = $rows
            ->filter(function (DatabaseNotification $notification) use ($subjects): bool {
                $taskId = $this->taskId($notification);
                $task = $taskId === null ? null : $subjects->get($taskId);

                return $task !== null && $this->reaches($task);
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
                (string) $comment->id => Str::limit(Str::squish(Mentions::toPlainText($comment->body)), self::EXCERPT_LENGTH),
            ]);
    }

    /**
     * @param  Collection<int, User>  $actors
     * @param  Collection<string, Task>  $subjects
     * @param  Collection<string, string>  $excerpts
     * @return array<string, mixed>
     */
    private function row(DatabaseNotification $notification, Collection $actors, Collection $subjects, Collection $excerpts, PersonSummary $people): array
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
            'actor' => $people->ofNullable($actor),
            'excerpt' => $commentId === null ? null : $excerpts->get($commentId),
            'subject' => $task === null ? null : $this->subject($task),
        ];
    }

    /**
     * @return array{type: string, id: string|null, title: string|null, url: string|null, projects: list<array<string, mixed>>}
     */
    private function subject(Task $task): array
    {
        if (! $this->reaches($task)) {
            return ['type' => 'task', 'id' => null, 'title' => null, 'url' => null, 'projects' => []];
        }

        return [
            'type' => 'task',
            'id' => $task->id,
            'title' => $task->title,
            'url' => route('tasks.show', $task->id),
            'projects' => array_values($task->projects
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'color' => $project->color?->value,
                ])
                ->all()),
        ];
    }

    private function reaches(Task $task): bool
    {
        return $task->getAttribute('reachable') === true;
    }

    private function shortType(DatabaseNotification $notification): string
    {
        return match ($notification->type) {
            TaskAssignedNotification::class => 'task.assigned',
            TaskCollaboratorAddedNotification::class => 'task.collaborator_added',
            CommentPostedNotification::class => 'comment.posted',
            MentionedInCommentNotification::class => 'comment.mentioned',
            default => 'unknown',
        };
    }

    private function actorId(DatabaseNotification $notification): ?int
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        $id = $data['assigned_by_id'] ?? $data['added_by_id'] ?? $data['author_id'] ?? null;

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
