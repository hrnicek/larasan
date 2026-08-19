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
 * @property int $user_id
 * @property WorkspaceRole $role
 * @property WorkspaceMembershipStatus $status
 * @property CarbonImmutable|null $joined_at
 */
#[UseFactory(WorkspaceMembershipFactory::class)]
class WorkspaceMembership extends Model
{
    /** @use HasFactory<WorkspaceMembershipFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['workspace_id', 'user_id', 'role', 'status', 'joined_at'];

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'status' => WorkspaceMembershipStatus::class,
            'joined_at' => 'immutable_datetime',
        ];
    }
}
