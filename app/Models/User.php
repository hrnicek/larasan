<?php

namespace App\Models;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\UiTheme;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Scout\Searchable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $current_workspace_id
 * @property UiTheme $ui_theme
 * @property int|null $avatar_preset
 * @property string|null $avatar_path
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'avatar_path'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, Searchable, TwoFactorAuthenticatable;

    /**
     * Name and email only: the index must never receive credentials or two-factor secrets.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => (string) $this->name,
            'email' => (string) $this->email,
        ];
    }

    /** Workspace switches write this row, so only indexed fields trigger a reindex. */
    public function searchIndexShouldBeUpdated(): bool
    {
        return $this->wasChanged(['name', 'email']) || $this->wasRecentlyCreated;
    }

    /** @return BelongsTo<Workspace, $this> */
    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    /**
     * Explicit memberships only; workspace-visible projects are resolved by query. See ADR-0006.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_memberships')
            ->withPivot(['id', 'access_level'])
            ->withTimestamps();
    }

    /** @return HasMany<WorkspaceMembership, $this> */
    public function workspaceMemberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    /**
     * @return BelongsToMany<Workspace, $this>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_memberships')
            ->withPivot(['id', 'role', 'status', 'joined_at'])
            ->withTimestamps()
            ->wherePivot('status', WorkspaceMembershipStatus::Active->value);
    }

    /**
     * Replaces Laravel's default `App.Models.User.{id}` channel with the `user.{id}` channel
     * authorized in routes/channels.php. See ADR-0008.
     */
    public function receivesBroadcastNotificationsOn(Notification $notification): string
    {
        return 'user.'.$this->id;
    }

    /**
     * Mirrors the database default, which a new model lacks until reloaded and strict mode rejects.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'ui_theme' => UiTheme::Slate->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'ui_theme' => UiTheme::class,
        ];
    }
}
