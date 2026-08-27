<?php

declare(strict_types=1);

namespace App\Domain\Project\Models;

use App\Domain\CustomField\Models\CustomField;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Access\MembershipRegistry;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectIcon;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Html\RichText;
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
use Laravel\Scout\Searchable;

/**
 * @property string $id
 * @property list<string>|null $list_columns
 * @property string $workspace_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property ProjectColor|null $color
 * @property ProjectIcon|null $icon
 * @property int|null $owner_id
 * @property int|null $created_by
 * @property ProjectDefaultView $default_view
 * @property ProjectVisibility $visibility
 * @property ProjectAccessLevel $default_access_level
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $archived_at
 * @property-read Workspace $workspace
 *
 * Present only when a query asks for it, which is what `null` means here: `withExists('stars')`
 * answers whether *the actor that query named* starred this project. It is not a column and not
 * a fact about the project — two people reading the same row get two different answers.
 * @property-read bool|null $stars_exists
 */
#[UseFactory(ProjectFactory::class)]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUuids, Searchable, SoftDeletes;

    /**
     * What the search engine is told (ADR-0016).
     *
     * The name, the description as words, and the slug — somebody who pastes a URL fragment is
     * looking for the project it addresses. `visibility` is indexed as a fact about the row, not
     * as a permission: what an actor may open is decided by `VisibleProjectsForUser` when the
     * rows are read.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'workspace_id' => (string) $this->workspace_id,
            'name' => (string) $this->name,
            'slug' => (string) $this->slug,
            'description' => RichText::toPlainText($this->description),
            'archived' => $this->archived_at !== null,
        ];
    }

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
     * Whether the actor may change the contents of this project — its columns, and what is
     * placed in them. Changing what is inside a project is editing it rather than managing
     * it, so Editor access is enough; but the workspace capability still has to allow that
     * kind of change, and an actor who cannot see the project cannot change anything in it
     * (ADR-0006 with ADR-0010, ADR-0004).
     */
    public function allowsChangesBy(User $user, Capability $capability): bool
    {
        // An archived project is a record of what happened, not a board somebody is still
        // working on. Restoring it goes through `isManageableBy()` instead, so an archived
        // project is never stuck.
        return ! $this->isArchived()
            && $this->isVisibleTo($user)
            && $this->workspace->membershipFor($user)?->allows($capability) === true
            && $this->accessLevelFor($user)?->canEdit() === true;
    }

    /**
     * Whether the actor may take part in this project's conversation. The comment half of
     * `allowsChangesBy()`, and archived for the same reason: a closed board is read.
     */
    public function allowsCommentsBy(User $user): bool
    {
        return ! $this->isArchived()
            && $this->isVisibleTo($user)
            && $this->workspace->membershipFor($user)?->allows(Capability::CommentCreate) === true
            && $this->accessLevelFor($user)?->canComment() === true;
    }

    /**
     * The actor's access to this project, whether or not they were named on it.
     *
     * A membership row is the answer whenever there is one, in both directions: a Viewer row on
     * a project the whole workspace may edit is a deliberate restriction, not an oversight to
     * be topped up. Without a row, a workspace-visible project answers with its own
     * `default_access_level`, and a private one answers with nothing — that is the whole of
     * what visibility means for writing (ADR-0006, amended).
     *
     * A guest is never covered by the default. They reach what they were explicitly given and
     * nothing else, which is the same sentence `isVisibleTo()` writes for reading.
     */
    public function accessLevelFor(User $user): ?ProjectAccessLevel
    {
        $membership = $this->memberFor($user);

        if ($membership instanceof ProjectMembership) {
            return $membership->access_level;
        }

        if ($this->visibility !== ProjectVisibility::Workspace) {
            return null;
        }

        return $this->workspace->membershipFor($user)?->role->isGuest() === false
            ? $this->default_access_level
            : null;
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
        return app(MembershipRegistry::class)->forProject($this, $user);
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
     * The workspace fields this project shows on its screens.
     *
     * A workspace can define more than any one project wants, so this is a decision per project
     * rather than a consequence of defining (Phase 150).
     *
     * @return BelongsToMany<CustomField, $this>
     */
    public function customFields(): BelongsToMany
    {
        return $this->belongsToMany(CustomField::class, 'project_custom_fields')
            ->withPivot('position')
            ->orderBy('project_custom_fields.position');
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

    /** @return HasMany<ProjectStar, $this> */
    public function stars(): HasMany
    {
        return $this->hasMany(ProjectStar::class);
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
            'icon' => ProjectIcon::class,
            'default_view' => ProjectDefaultView::class,
            'visibility' => ProjectVisibility::class,
            'default_access_level' => ProjectAccessLevel::class,
            'start_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'archived_at' => 'immutable_datetime',
            /*
             * The order the list draws its columns in — read whole, written whole, never sorted
             * or filtered by, which is what makes JSON the right answer here and the wrong one
             * for a custom field's value. Null is a project nobody has reordered, not a project
             * with no columns.
             */
            'list_columns' => 'array',
        ];
    }
}
