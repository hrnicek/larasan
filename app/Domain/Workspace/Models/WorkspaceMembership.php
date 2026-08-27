<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Models;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\WorkspaceMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $workspace_id
 * @property int|null $user_id
 * @property string|null $email
 * @property WorkspaceRole $role
 * @property WorkspaceMembershipStatus $status
 * @property CarbonImmutable|null $joined_at
 * @property CarbonImmutable|null $expires_at
 * @property int|null $invited_by
 * @property-read User|null $invitedBy
 * @property-read Workspace $workspace
 * @property-read User|null $user
 */
#[UseFactory(WorkspaceMembershipFactory::class)]
class WorkspaceMembership extends Model
{
    /** @use HasFactory<WorkspaceMembershipFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['workspace_id', 'user_id', 'email', 'role', 'status', 'joined_at', 'expires_at', 'invited_by'];

    /**
     * The single composed authorization question for a workspace. Callers ask this and
     * never `role->allows()` directly: the role is a pure function of the role, so it
     * answers true for an invited, declined, revoked or expired membership, and a rule
     * every call site has to remember is a rule one call site will forget (ADR-0010).
     */
    public function allows(Capability $capability): bool
    {
        return $this->status->grantsAccess() && $this->role->allows($capability);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Whether an account has taken this row over. An invitation to an address nobody has
     * registered yet holds no `user_id`, and every authorization path filters by that
     * column — so an unclaimed row grants nothing to anybody, by construction rather than
     * by a rule each call site has to remember.
     */
    public function isClaimed(): bool
    {
        return $this->user_id !== null;
    }

    /** The address this row answers to: the one invited, or the account's own. */
    public function address(): ?string
    {
        return $this->email ?? $this->user?->email;
    }

    /**
     * An invitation past its deadline is not acceptable, whatever its status column says
     * — the sweep that flips `invited` to `expired` runs on a schedule, and an acceptance
     * arriving before it must not win the race.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'status' => WorkspaceMembershipStatus::class,
            'joined_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
