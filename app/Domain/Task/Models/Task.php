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
 * @property-read Workspace $workspace
 */
#[UseFactory(TaskFactory::class)]
class Task extends Model implements Attachable, Commentable
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, HasUuids, Searchable, SoftDeletes;

    /**
     * What the search engine is told, which is less than what the screen draws.
     *
     * Title and description are what somebody types a search box to find; the description
     * arrives as markup and goes in as words, because `p`, `li` and `strong` are terms to a
     * search engine and *strong* would otherwise return every task with a bold word in it.
     *
     * `workspace_id` is a filter, not a permission: every query sends it so one tenant's typing
     * cannot rank against another's data, and the rows are still hydrated through
     * `ReachableTasks` afterwards. Nothing here decides what anybody may see (ADR-0016).
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'workspace_id' => (string) $this->workspace_id,
            'title' => (string) $this->title,
            'description' => RichText::toPlainText($this->description),
            'completed' => $this->completed_at !== null,
            // Seconds since the epoch: Meilisearch sorts numbers, not ISO strings.
            'created_at' => (int) $this->created_at?->getTimestamp(),
        ];
    }

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
     * A comment carries the workspace of the thing it is about, and this is where a task says
     * which that is (`Commentable`).
     */
    public function workspaceId(): string
    {
        return $this->workspace_id;
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
     * The answers this task has given to its projects' custom fields (Phase 150).
     *
     * @return HasMany<TaskCustomFieldValue, $this>
     */
    public function customFieldValues(): HasMany
    {
        return $this->hasMany(TaskCustomFieldValue::class);
    }

    /**
     * What this task is about. Ordered by name so a card's chips do not reshuffle between
     * requests for no reason anybody can see.
     *
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'task_tag')->orderBy('name');
    }

    /**
     * What is attached to this task, oldest first — then by key, because `created_at` is
     * `timestamp(0)` and two files uploaded in the same second would otherwise come back in
     * whichever order PostgreSQL chose that day.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->oldest('created_at')->orderBy('id');
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

    /**
     * The rows that say who starred this task. A star is one person's shortcut, so there is no
     * `starrers()` beside it the way `followers()` sits beside `follows()`: nothing draws the
     * list of people who starred something.
     *
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
