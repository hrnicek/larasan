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

final readonly class ProjectFilesQuery
{
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
            // Joins bypass SoftDeletes; the workspace is asserted on the file itself.
            ->join('files', function (JoinClause $file) use ($project): void {
                $file->on('files.id', '=', 'attachments.file_id')
                    ->whereNull('files.deleted_at')
                    ->where('files.workspace_id', '=', $project->workspace_id);
            })
            ->where('attachments.attachable_type', (string) Relation::getMorphAlias(Task::class))
            ->whereIn('attachments.attachable_id', TaskProjectMembership::query()
                ->visible()
                ->where('project_id', $project->id)
                ->select('task_id'))
            ->with(PersonSummary::eager('file.uploader'))
            // The sort column comes from the enum, never from request input.
            ->orderBy($sort->column(), $descending ? 'desc' : 'asc')
            // Tie-breaker for stable pagination.
            ->orderByDesc('attachments.id')
            ->paginate(perPage: $perPage, page: $page);
    }

    /**
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
            'uploader' => PersonSummary::fromNullable($uploader),
            'task' => $subject instanceof Task ? ['id' => $subject->id, 'title' => $subject->title] : null,
            'attachedAt' => $attachment->created_at?->toIso8601String(),
            // Mirrors AttachmentPolicy::delete() without a per-row policy call.
            'canDelete' => $moderates || $file->uploaded_by === $actor->id,
        ];
    }
}
