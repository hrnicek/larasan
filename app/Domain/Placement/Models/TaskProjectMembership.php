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
 * @property string $id
 * @property string $task_id
 * @property string $project_id
 * @property string|null $section_id
 * @property int $position
 * @property-read Task $task
 * @property-read Project $project
 */
#[UseFactory(TaskProjectMembershipFactory::class)]
class TaskProjectMembership extends Model
{
    /** @use HasFactory<TaskProjectMembershipFactory> */
    use HasFactory, HasUuids;

    public const POSITION_GAP = SparsePosition::GAP;

    // position is computed by the placement Actions and must never be mass assigned. See ADR-0009.
    protected $fillable = ['task_id', 'project_id', 'section_id'];

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeVisible(Builder $query): Builder
    {
        // Excludes soft-deleted tasks, whose placements are kept so a restore puts the card back.
        return $query->whereHas('task');
    }

    /**
     * @param  Builder<$this>  $query
     * @param  list<string>  $tags
     * @return Builder<$this>
     */
    public function scopeTaggedWithAll(Builder $query, array $tags): Builder
    {
        if ($tags === []) {
            return $query;
        }

        return $query->whereHas(
            'task',
            fn (Builder $tasks): Builder => $tasks->whereHas(
                'tags',
                fn (Builder $carried): Builder => $carried->whereIn('tags.id', $tags),
                '=',
                count(array_unique($tags)),
            ),
        );
    }

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
