<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Models;

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
 */
#[UseFactory(WorkspaceFactory::class)]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['owner_id', 'name', 'slug', 'timezone', 'settings'];

    /**
     * Deliberately explicit rather than a `creating` hook: a slug that appears by itself
     * is the hidden side effect the guidelines forbid, and callers that need to show the
     * slug before saving cannot ask a hook for it.
     *
     * Collisions resolve by counting, not by randomness. Each candidate is checked, so
     * the method cannot hand back a slug that is already taken — the first version
     * returned an unchecked random suffix, which the unique index met as a 500 — and a
     * counted sequence terminates, which a random one is not guaranteed to.
     *
     * A simultaneous insert can still take the candidate between the check and the
     * write. The database is the authority, not this method, which is why
     * CreateWorkspace retries once.
     */
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

    /**
     * The actor's membership row, or null when they have none. The policy asks this and
     * then asks the row — role alone never answers an authorization question, because it
     * cannot see whether the membership is still live (ADR-0010).
     */
    public function membershipFor(User $user): ?WorkspaceMembership
    {
        // Through the registry, because one request asks this many times and the row does
        // not change between the asks — see `MembershipRegistry` for why that is safe.
        return app(MembershipRegistry::class)->forWorkspace($this, $user);
    }

    /**
     * Whether an account is a live member of this workspace. Asked by the Actions that
     * accept a user id from outside — an assignee, a mention — where the id being valid
     * says nothing about the person belonging here.
     */
    public function hasActiveMember(int $userId): bool
    {
        return $this->memberships()
            ->where('user_id', $userId)
            ->where('status', WorkspaceMembershipStatus::Active->value)
            ->exists();
    }

    /**
     * Whether this membership is the only active owner left. ADR-0010's "cannot be
     * removed or demoted while last owner" has no database constraint behind it — the
     * count is a query, and the Actions that could break the rule ask it here so they
     * cannot each answer differently.
     */
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

        /*
         * `locking` is for the Actions, which read this and then write. `doesntExist()`
         * would compile to `select exists(... for update)`, which short-circuits and locks
         * only the first match, and PostgreSQL refuses `FOR UPDATE` with an aggregate at
         * all — so the rows are fetched and counted here. Locking the row about to be
         * written as well means a competing transaction blocks rather than deadlocking.
         */
        $this->memberships()->whereKey($membership->id)->lockForUpdate()->get();

        return $others->lockForUpdate()->get(['id'])->all() === [];
    }

    /**
     * Every project in the workspace, archived and active alike. Callers listing projects
     * for a person filter by visibility and membership as well — that rule lives in one
     * query object (TASK-040-011), not in each caller.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * The workspace's own vocabulary (Phase 140).
     *
     * @return HasMany<Tag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * Every task in the workspace, whatever project it appears in — including the ones
     * that appear in none, which are still the workspace's tasks (ADR-0003).
     *
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
     * Every membership row, whatever its status. Callers that mean "people who are
     * actually in this workspace" filter by status; naming this `members()` without the
     * filter would have invited the opposite assumption.
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
