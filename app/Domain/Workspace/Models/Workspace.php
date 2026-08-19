<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Models;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
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
     * The suffix closes the collision window that validation cannot: two requests naming
     * a workspace identically both pass a uniqueness check and one loses to the unique
     * index. Callers retry with a fresh call.
     */
    public static function slugFor(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';

        if (! static::query()->where('slug', $base)->exists()) {
            return $base;
        }

        return $base.'-'.Str::lower(Str::random(6));
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
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
