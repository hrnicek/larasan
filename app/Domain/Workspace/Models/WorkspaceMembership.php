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
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use LogicException;

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
     * Use this rather than role->allows(), which ignores membership status. See ADR-0010.
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

    public function isClaimed(): bool
    {
        return $this->user_id !== null;
    }

    public function invitee(): User|AnonymousNotifiable
    {
        $user = $this->user;

        return $user instanceof User ? $user : Notification::route('mail', $this->address());
    }

    public function address(): string
    {
        return $this->email ?? $this->user->email
            ?? throw new LogicException('A membership belongs to an account or to an address.');
    }

    /**
     * Checks the deadline directly, since the scheduled sweep may not have marked the row yet.
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
