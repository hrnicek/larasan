<?php

declare(strict_types=1);

namespace App\Domain\Placement\Models;

use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Models\Task;
use Database\Factories\TaskProjectMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a task appears (ADR-0003). One row is one card: the same task in two projects is
 * two rows and still one task, and detaching it from a project deletes the row and leaves
 * the task alone.
 *
 * No soft deletes. A detached placement is a card nobody can see and a slot nobody can
 * take — `UNIQUE(task_id, project_id)` and the slot guards would both count a hidden row,
 * so re-attaching a task would collide with its own tombstone.
 *
 * The table carries no `workspace_id`, and must not: it is scoped by joining the aggregate
 * that owns it (`docs/architecture/database.md`), which is also the only place the two
 * ends can be proven to be in the *same* workspace.
 *
 * @property string $id
 * @property string $task_id
 * @property string $project_id
 * @property string|null $section_id
 * @property int $position
 */
#[UseFactory(TaskProjectMembershipFactory::class)]
class TaskProjectMembership extends Model
{
    /** @use HasFactory<TaskProjectMembershipFactory> */
    use HasFactory, HasUuids;

    /** The gap ADR-0009 specifies, defined once in `SparsePosition`. */
    public const POSITION_GAP = SparsePosition::GAP;

    /**
     * `position` is absent by design: a client never sends one (ADR-0009). A move says
     * "place this after that one" and the Action computes what that means, so a fillable
     * position would be a route into the sequence for stale data to corrupt.
     */
    protected $fillable = ['task_id', 'project_id', 'section_id'];

    /**
     * The cards a column actually draws. A soft-deleted task keeps its placement, so that
     * restoring the task puts the card back where it was — but until then there is nothing
     * to render, and a board that drew the row would draw a card with no task on it.
     *
     * Counting and rendering must both go through here, or a column's header disagrees with
     * its contents.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereHas('task');
    }

    /** Ungrouped: in the project, in no column — the list view's default bucket. */
    public function isUngrouped(): bool
    {
        return $this->section_id === null;
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}
