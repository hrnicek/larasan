<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Models;

use App\Domain\CustomField\Models\CustomField;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Access\MembershipRegistry;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property int $owner_id
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property array<string, mixed> $settings
 * @property-read User $owner
 */
#[UseFactory(WorkspaceFactory::class)]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['owner_id', 'name', 'slug', 'timezone', 'settings'];

    /** A concurrent insert can still take the slug; CreateWorkspace retries once. */
    public static function slugFor(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $candidate = $base;
        $suffix = 1;

        while (static::query()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.++$suffix;
        }

        return $candidate;
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function membershipFor(User $user): ?WorkspaceMembership
    {
        return app(MembershipRegistry::class)->forWorkspace($this, $user);
    }

    public function hasActiveMember(int $userId): bool
    {
        return $this->memberships()
            ->where('user_id', $userId)
            ->where('status', WorkspaceMembershipStatus::Active->value)
            ->exists();
    }

    public function isLastOwner(WorkspaceMembership $membership, bool $locking = false): bool
    {
        if (! $membership->role->isOwner() || ! $membership->status->grantsAccess()) {
            return false;
        }

        $others = $this->memberships()
            ->where('role', WorkspaceRole::Owner->value)
            ->where('status', WorkspaceMembershipStatus::Active->value)
            ->whereKeyNot($membership->id);

        if (! $locking) {
            return $others->doesntExist();
        }

        // PostgreSQL refuses FOR UPDATE with an aggregate and EXISTS locks only the first match,
        // so rows are fetched; the target row is locked too so writers block rather than deadlock.
        $this->memberships()->whereKey($membership->id)->lockForUpdate()->get();

        return $others->lockForUpdate()->get(['id'])->all() === [];
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<CustomField, $this>
     */
    public function customFields(): HasMany
    {
        return $this->hasMany(CustomField::class);
    }

    /**
     * @return HasMany<Tag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<WorkspaceMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    /**
     * Includes every membership status; use members() for active members only.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_memberships')
            ->withPivot(['id', 'role', 'status', 'joined_at'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->users()->wherePivot('status', WorkspaceMembershipStatus::Active->value);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }
}
