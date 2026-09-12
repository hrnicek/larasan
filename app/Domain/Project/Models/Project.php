<?php

declare(strict_types=1);

namespace App\Domain\Project\Models;

use App\Domain\CustomField\Models\CustomField;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Access\MembershipRegistry;
use App\Domain\Shared\Casts\AsAccentColor;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectIcon;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Html\RichText;
use App\Domain\Shared\ValueObjects\AccentColor;
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
 * @property AccentColor|null $color
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
 * @property-read bool|null $stars_exists
 */
#[UseFactory(ProjectFactory::class)]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUuids, Searchable, SoftDeletes;

    /**
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

    public function isManageableBy(User $user): bool
    {
        return $this->workspace->membershipFor($user)?->allows(Capability::ProjectUpdate) === true
            && $this->memberFor($user)?->access_level->canManageProject() === true;
    }

    public function allowsChangesBy(User $user, Capability $capability): bool
    {
        // Archived projects are read-only; restoring is authorized by isManageableBy() instead.
        return ! $this->isArchived()
            && $this->isVisibleTo($user)
            && $this->workspace->membershipFor($user)?->allows($capability) === true
            && $this->accessLevelFor($user)?->canEdit() === true;
    }

    public function allowsCommentsBy(User $user): bool
    {
        return ! $this->isArchived()
            && $this->isVisibleTo($user)
            && $this->workspace->membershipFor($user)?->allows(Capability::CommentCreate) === true
            && $this->accessLevelFor($user)?->canComment() === true;
    }

    /**
     * A membership row always wins, even when it grants less than the default.
     * Guests never receive the default. See ADR-0006.
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

    public function memberFor(User $user): ?ProjectMembership
    {
        return app(MembershipRegistry::class)->forProject($this, $user);
    }

    /**
     * @return HasMany<Section, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('position');
    }

    /**
     * @return BelongsToMany<CustomField, $this>
     */
    public function customFields(): BelongsToMany
    {
        return $this->belongsToMany(CustomField::class, 'project_custom_fields')
            ->withPivot('position')
            ->orderBy('project_custom_fields.position');
    }

    /**
     * Unordered: board order needs sections.position, which is a join rather than a relation.
     *
     * @return HasMany<TaskProjectMembership, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(TaskProjectMembership::class);
    }

    /**
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
            'color' => AsAccentColor::class,
            'icon' => ProjectIcon::class,
            'default_view' => ProjectDefaultView::class,
            'visibility' => ProjectVisibility::class,
            'default_access_level' => ProjectAccessLevel::class,
            'start_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'archived_at' => 'immutable_datetime',
            // Null means the default order, not an empty column list.
            'list_columns' => 'array',
        ];
    }
}
