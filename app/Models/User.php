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
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, Searchable, TwoFactorAuthenticatable;

    /**
     * What the search engine is told about a person (ADR-0016).
     *
     * Name and email, which is what somebody types to find a colleague, and nothing else — a
     * user row carries a password hash, two-factor secrets and recovery codes, and an index is
     * a second copy of whatever it is handed.
     *
     * There is no `workspace_id` here because a person belongs to several: the index holds every
     * user in the installation, and `PersonResults` joins `workspace_memberships` to decide who
     * this actor may be shown. That join is the boundary; nothing in this array is.
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

    /**
     * Only a change to what the index holds is worth an indexing job.
     *
     * Every workspace switch writes `current_workspace_id` on this row, and without this a
     * person moving between two workspaces would queue an indexing job per move — for a document
     * whose two fields did not change.
     */
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
     * Projects this user is an explicit member of. Workspace-visible projects they can
     * also see are not here: that is a visibility rule, answered by a query, not a
     * relationship (ADR-0006).
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
     * Workspaces this user has an active membership in. Ownership is a separate concept:
     * an owner also holds a membership row, and a workspace whose owner column points
     * here without one is a bug the membership tests catch.
     *
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
     * ADR-0008 names the user's channel `private-user.{user}`, while Laravel's
     * notification broadcasting defaults to `App.Models.User.{id}`. Reconciled here in one
     * direction, so the application has one user channel that `routes/channels.php`
     * authorizes rather than two half-working ones.
     */
    public function receivesBroadcastNotificationsOn(Notification $notification): string
    {
        return 'user.'.$this->id;
    }

    /**
     * @return array<string, string>
     */
    /**
     * A database default fills the column but leaves the *model* without the attribute until it
     * is read back, and `Model::shouldBeStrict()` throws on a missing one — so the first request
     * after a sign-up went through `HandleUiTheme` and 500'd. The default belongs on both sides.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'ui_theme' => UiTheme::Slate->value,
    ];

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
