<?php

declare(strict_types=1);

namespace App\Domain\Task\Models;

use App\Domain\Comment\Models\Comment;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A task belongs to a workspace and to no project (ADR-0003). Where it appears is a
 * separate question, answered by `task_project_memberships`: the relations below read
 * placement, and no column here records it.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $parent_id
 * @property string $title
 * @property string|null $description
 * @property TaskPriority $priority
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $completed_at
 * @property int|null $completed_by
 * @property int|null $assignee_id
 * @property int|null $created_by
 */
#[UseFactory(TaskFactory::class)]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Completion, ownership and authorship are absent by design: each is set by the Action
     * that owns the operation, and a fillable column is one a request can reach.
     */
    protected $fillable = [
        'parent_id',
        'title',
        'description',
        'priority',
        'due_at',
        'assignee_id',
    ];

    /**
     * Completion is this column and nothing else. A "Done" column is a name somebody chose
     * (ADR-0004), and inferring completion from placement is how a rename closes work.
     */
    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Ordered by creation, then by key: `created_at` is `timestamp(0)`, so two subtasks
     * added in the same second would otherwise come back in whichever order PostgreSQL
     * chose that day. The key is UUIDv7, which breaks the tie the way time would.
     *
     * @return HasMany<Task, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest('created_at')->orderBy('id');
    }

    /**
     * Where this task appears (ADR-0003). Unordered on purpose: a task's placements are a
     * set, and `position` orders a task against its neighbours in one project rather than
     * ordering the projects against each other.
     *
     * @return HasMany<TaskProjectMembership, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(TaskProjectMembership::class);
    }

    /**
     * The projects this task appears in, by name — the chip list on a task, where the only
     * order a reader can follow is the one they can read.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'task_project_memberships')
            ->withPivot(['id', 'section_id', 'position'])
            ->withTimestamps()
            ->orderBy('projects.name');
    }

    /**
     * What has been said about this task, newest last — a conversation reads in the order it
     * happened.
     *
     * @return MorphMany<Comment, $this>
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable')->oldest('created_at')->orderBy('id');
    }

    /**
     * The rows that say who is watching. `followers()` is the people themselves — both exist
     * because an Action deletes a row and a screen draws a person.
     *
     * @return HasMany<TaskFollower, $this>
     */
    public function follows(): HasMany
    {
        return $this->hasMany(TaskFollower::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_followers')
            ->withPivot('id')
            ->orderBy('name');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'priority' => TaskPriority::class,
            'due_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
