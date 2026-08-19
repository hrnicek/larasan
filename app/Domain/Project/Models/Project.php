<?php

declare(strict_types=1);

namespace App\Domain\Project\Models;

use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectVisibility;
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
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $color
 * @property string|null $icon
 * @property int|null $owner_id
 * @property int|null $created_by
 * @property ProjectDefaultView $default_view
 * @property ProjectVisibility $visibility
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $archived_at
 */
#[UseFactory(ProjectFactory::class)]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUuids, SoftDeletes;

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

    /**
     * Unique **within the workspace**, unlike `Workspace::slugFor()`: two tenants may both
     * have a project called Web, and the composite index says so. Counted rather than
     * random for the same reasons — each candidate is checked, and counting terminates.
     */
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

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
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
            'default_view' => ProjectDefaultView::class,
            'visibility' => ProjectVisibility::class,
            'start_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'archived_at' => 'immutable_datetime',
        ];
    }
}
