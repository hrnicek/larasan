<?php

declare(strict_types=1);

namespace App\Domain\Project\Models;

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property ProjectColor|null $color
 * @property string|null $icon
 * @property int|null $owner_id
 * @property int|null $created_by
 * @property ProjectDefaultView $default_view
 * @property ProjectVisibility $visibility
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $archived_at
 */
#[UseFactory(ProjectFactory::class)]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'color',
        'icon',
        'default_view',
        'visibility',
        'start_date',
        'due_date',
    ];

    /**
     * Unique **within the workspace**, unlike `Workspace::slugFor()`: two tenants may both
     * have a project called Web, and the composite index says so. Counted rather than
     * random for the same reasons — each candidate is checked, and counting terminates.
     */
    public static function slugFor(Workspace $workspace, string $name): string
    {
        $base = Str::slug($name) ?: 'project';
        $candidate = $base;
        $suffix = 1;

        while (static::query()->where('workspace_id', $workspace->id)->where('slug', $candidate)->withTrashed()->exists()) {
            $candidate = $base.'-'.++$suffix;
        }

        return $candidate;
    }

    /**
     * Managing a project takes both halves: the workspace capability says the actor may
     * change projects at all, the access level says they may change *this* one (ADR-0006
     * with ADR-0010). Neither alone is enough, and the policy, the Actions and the UI all
     * ask here so they cannot drift apart.
     */
    public function isManageableBy(User $user): bool
    {
        return $this->workspace->membershipFor($user)?->allows(Capability::ProjectUpdate) === true
            && $this->memberFor($user)?->access_level->canManageProject() === true;
    }

    /**
     * Whether the actor may shape this project's columns. Editing the sections is editing
     * the project rather than managing it, so Editor access is enough — but the workspace
     * capability still has to allow it, and an actor who cannot see the project cannot
     * change anything in it (ADR-0006 with ADR-0010, ADR-0004).
     */
    public function allowsSectionChangesBy(User $user, Capability $capability): bool
    {
        return $this->isVisibleTo($user)
            && $this->workspace->membershipFor($user)?->allows($capability) === true
            && $this->memberFor($user)?->access_level->canEdit() === true;
    }

    /**
     * Read access. An explicit project membership always grants it; `visibility =
     * workspace` grants it to workspace **members** without one — and never to a guest,
     * who reaches only what they were explicitly given (ADR-0006 with ADR-0010).
     */
    public function isVisibleTo(User $user): bool
    {
        $membership = $this->workspace->membershipFor($user);

        if ($membership?->status->grantsAccess() !== true) {
            return false;
        }

        if ($this->memberFor($user) !== null) {
            return true;
        }

        return $this->visibility === ProjectVisibility::Workspace
            && ! $membership->role->isGuest();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * The actor's membership row, or null. Named like `Workspace::membershipFor()` because
     * it answers the same shape of question one level down, and the project policy
     * composes the two.
     */
    public function memberFor(User $user): ?ProjectMembership
    {
        return $this->memberships()->where('user_id', $user->id)->first();
    }

    /**
     * The project's sections, in the order a board renders them. Ordered here rather than
     * at each caller: a section list in some other order is not a section list, and
     * ADR-0009 makes the order a column rather than an accident of insertion.
     *
     * @return HasMany<Section, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('position');
    }

    /**
     * Every card in this project, columns and ungrouped bucket alike. Unordered on purpose:
     * a board's order is `sections.position` first and `position` second, which is a join
     * rather than a relation, and ordering by `section_id` would order by a UUID.
     * `Section::placements()` is where order within a column lives.
     *
     * @return HasMany<TaskProjectMembership, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(TaskProjectMembership::class);
    }

    /**
     * The tasks this project holds. A task reached this way is a task, not a copy: it is
     * owned by the workspace and may appear in other projects too (ADR-0003).
     *
     * @return BelongsToMany<Task, $this>
     */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_project_memberships')
            ->withPivot(['id', 'section_id', 'position'])
            ->withTimestamps();
    }

    /** @return HasMany<ProjectMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMembership::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_memberships')
            ->withPivot(['id', 'access_level'])
            ->withTimestamps();
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
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
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'color' => ProjectColor::class,
            'default_view' => ProjectDefaultView::class,
            'visibility' => ProjectVisibility::class,
            'start_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'archived_at' => 'immutable_datetime',
        ];
    }
}
