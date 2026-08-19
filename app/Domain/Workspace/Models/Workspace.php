<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Models;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
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
        return $this->memberships()->where('user_id', $user->id)->first();
    }

    /**
     * Whether this membership is the only active owner left. ADR-0010's "cannot be
     * removed or demoted while last owner" has no database constraint behind it — the
     * count is a query, and the Actions that could break the rule ask it here so they
     * cannot each answer differently.
     */
    public function isLastOwner(WorkspaceMembership $membership): bool
    {
        if (! $membership->role->isOwner() || ! $membership->status->grantsAccess()) {
            return false;
        }

        return $this->memberships()
            ->where('role', WorkspaceRole::Owner->value)
            ->where('status', WorkspaceMembershipStatus::Active->value)
            ->whereKeyNot($membership->id)
            ->doesntExist();
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
