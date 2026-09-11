<?php

declare(strict_types=1);

namespace App\Domain\Project\Queries;

use App\Domain\File\Models\Attachment;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\FileKind;
use App\Domain\Shared\Enums\ProjectFileSort;
use App\Domain\Shared\Payloads\PersonSummary;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Everything hanging off a project's tasks: the files view's whole data source.
 *
 * A project has no attachments of its own — `Attachable` is implemented by `Task` alone — so
 * this view is not a second store of files but a second way into the ones already there. Which
 * is why it has no endpoint: a row is downloaded and removed through the attachment endpoints
 * the task detail already uses, under the same policy.
 *
 * Two rules carried over from the other three views:
 *
 * - **scope is proven in the query.** The workspace is a column on `files`, and belonging to
 *   *this* project is a join through the placements — the pure relationship table is never
 *   queried standalone (`docs/architecture/database.md`).
 * - **authorization is computed once.** Whether the reader may remove a row is `file.delete` in
 *   the workspace or having uploaded it; asking `AttachmentPolicy` per row would be an N+1 of
 *   the kind TASK-070-015 removed from the board.
 *
 * One file attached to two of the project's tasks is two rows, because an attachment is the
 * claim being listed — removing it from one task is not removing it from the other.
 */
final readonly class ProjectFilesQuery
{
    /**
     * A screenful and then some. The table is one row per file, so this is a larger page than
     * the feeds use and still bounded — a project with ten thousand attachments renders like
     * any other.
     */
    public const PER_PAGE = 50;

    /**
     * @return array{
     *     files: list<array<string, mixed>>,
     *     meta: array{page: int, perPage: int, total: int, hasMore: bool, sort: string, direction: string},
     * }
     */
    public function __invoke(
        Project $project,
        User $actor,
        int $page = 1,
        ?ProjectFileSort $sort = null,
        ?bool $descending = null,
        int $perPage = self::PER_PAGE,
    ): array {
        $sort ??= ProjectFileSort::Added;
        $descending ??= $sort->defaultsToDescending();

        $attachments = $this->paginate($project, $page, $perPage, $sort, $descending);

        /** @var Collection<int, Attachment> $rows */
        $rows = $attachments->getCollection();

        $tasks = $this->tasks($rows);
        $moderates = $project->workspace->membershipFor($actor)?->allows(Capability::FileDelete) === true;

        return [
            'files' => array_values($rows
                ->map(fn (Attachment $attachment): array => $this->row($attachment, $tasks, $actor, $moderates))
                ->all()),
            'meta' => [
                'page' => $attachments->currentPage(),
                'perPage' => $attachments->perPage(),
                'total' => $attachments->total(),
                'hasMore' => $attachments->hasMorePages(),
                // What the server understood of the ordering, echoed back, so the headers draw
                // the table that arrived rather than the one the client asked for.
                'sort' => $sort->value,
                'direction' => $descending ? 'desc' : 'asc',
            ],
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Attachment>
     */
    private function paginate(
        Project $project,
        int $page,
        int $perPage,
        ProjectFileSort $sort,
        bool $descending,
    ): LengthAwarePaginator {
        return Attachment::query()
            ->select('attachments.*')
            /*
             * Joined rather than merely eager-loaded, because two of the three orderings are
             * columns of `files`. The join carries the scope with it: the workspace is the
             * indexed column the tenancy rests on, and a removed file is soft-deleted, which the
             * relation's own scope would apply but a join has to say out loud.
             */
            ->join('files', function (JoinClause $file) use ($project): void {
                $file->on('files.id', '=', 'attachments.file_id')
                    ->whereNull('files.deleted_at')
                    ->where('files.workspace_id', '=', $project->workspace_id);
            })
            ->where('attachments.attachable_type', (string) Relation::getMorphAlias(Task::class))
            /*
             * The subject has to be a task this project still draws: `visible()` is the scope
             * the board and the list count through, so a soft-deleted task takes its files out
             * of this table exactly as it takes its card off the board.
             */
            ->whereIn('attachments.attachable_id', TaskProjectMembership::query()
                ->visible()
                ->where('project_id', $project->id)
                ->select('task_id'))
            ->with(PersonSummary::eager('file.uploader'))
            // The column comes from the enum, never from the request: a client-supplied string
            // has no business reaching an `order by`.
            ->orderBy($sort->column(), $descending ? 'desc' : 'asc')
            // The id breaks the tie, because two files attached in the same second — or two files
            // of the same size — would otherwise page in whichever order PostgreSQL chose today.
            ->orderByDesc('attachments.id')
            ->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Every task on this page, in one read, and only the two columns the table draws — a task
     * carries its description, and fifty of those is a page of rich text nobody here reads.
     *
     * Resolved by id rather than through the morph relation for the reason the inbox resolves
     * its subjects that way: one `whereIn` beats a relation per row, and the type is already
     * known — `Task` is the only thing a file can hang from.
     *
     * @param  Collection<int, Attachment>  $rows
     * @return Collection<string, Task>
     */
    private function tasks(Collection $rows): Collection
    {
        $ids = $rows->pluck('attachable_id')->unique()->values();

        if ($ids->isEmpty()) {
            return new Collection;
        }

        return Task::query()->whereIn('id', $ids)->get(['id', 'title'])->keyBy('id');
    }

    /**
     * @param  Collection<string, Task>  $tasks
     * @return array<string, mixed>
     */
    private function row(Attachment $attachment, Collection $tasks, User $actor, bool $moderates): array
    {
        $file = $attachment->file;
        $uploader = $file->uploader;
        $subject = $tasks->get($attachment->attachable_id);

        return [
            'id' => $attachment->id,
            'name' => $file->original_name,
            'size' => $file->size,
            'extension' => $file->extension,
            'kind' => FileKind::fromMime($file->mime_type, $file->extension)->value,
            /*
             * Null where the account is gone rather than an invented name, for the reason the
             * inbox nulls its actor: a file outlives the person who uploaded it, and the column
             * says so.
             */
            'uploader' => PersonSummary::fromNullable($uploader),
            /*
             * What it hangs from. Only ever a task today, and named as one, so the screen can
             * open the panel rather than guess what kind of thing this is.
             */
            'task' => $subject instanceof Task ? ['id' => $subject->id, 'title' => $subject->title] : null,
            'attachedAt' => $attachment->created_at?->toIso8601String(),
            // `AttachmentPolicy::delete()` as arithmetic. Reaching the subject is not asked
            // again: the reader is looking at the project the task is placed in.
            'canDelete' => $moderates || $file->uploaded_by === $actor->id,
        ];
    }
}
