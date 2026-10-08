<?php

declare(strict_types=1);

namespace App\Domain\Task\Models;

use App\Domain\Comment\Models\Comment;
use App\Domain\Comment\Models\Commentable;
use App\Domain\CustomField\Models\TaskCustomFieldValue;
use App\Domain\File\Models\Attachable;
use App\Domain\File\Models\Attachment;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Shared\Html\RichText;
use App\Domain\Tag\Models\Tag;
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
use Laravel\Scout\Searchable;

/**
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
 * @property-read Workspace $workspace
 */
#[UseFactory(TaskFactory::class)]
class Task extends Model implements Attachable, Commentable
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, HasUuids, Searchable, SoftDeletes;

    // Completion, workspace and creator columns are set by Actions and must never be mass assigned.
    protected $fillable = [
        'parent_id',
        'title',
        'description',
        'priority',
        'due_at',
        'assignee_id',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            // A search filter, not a permission: results are still hydrated through ReachableTasks. See ADR-0016.
            'workspace_id' => (string) $this->workspace_id,
            'title' => (string) $this->title,
            'description' => RichText::toPlainText($this->description),
            'completed' => $this->completed_at !== null,
            // Meilisearch sorts numbers, not ISO strings.
            'created_at' => (int) $this->created_at?->getTimestamp(),
        ];
    }

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
     * @return HasMany<Task, $this>
     */
    public function children(): HasMany
    {
        // created_at has second precision, so the UUIDv7 key breaks ties in creation order.
        return $this->hasMany(self::class, 'parent_id')->oldest('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<TaskProjectMembership, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(TaskProjectMembership::class);
    }

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'task_project_memberships')
            ->withPivot(['id', 'section_id', 'position'])
            ->withTimestamps()
            ->orderBy('projects.name');
    }

    public function workspaceId(): string
    {
        return $this->workspace_id;
    }

    /**
     * @return MorphMany<Comment, $this>
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable')->oldest('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<TaskCustomFieldValue, $this>
     */
    public function customFieldValues(): HasMany
    {
        return $this->hasMany(TaskCustomFieldValue::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'task_tag')->orderBy('name');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('position');
    }

    /**
     * @return HasMany<TaskFollower, $this>
     */
    public function follows(): HasMany
    {
        return $this->hasMany(TaskFollower::class);
    }

    /**
     * @return HasMany<TaskStar, $this>
     */
    public function stars(): HasMany
    {
        return $this->hasMany(TaskStar::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_followers')
            ->withPivot('id')
            ->orderBy('users.name');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return HasMany<TaskCollaborator, $this>
     */
    public function collaborations(): HasMany
    {
        return $this->hasMany(TaskCollaborator::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_collaborators')
            ->withPivot('id')
            ->orderBy('users.name');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
