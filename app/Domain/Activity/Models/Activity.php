<?php

declare(strict_types=1);

namespace App\Domain\Activity\Models;

use App\Domain\Shared\Enums\ActivityType;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Something that happened, written down.
 *
 * Append-only: the table has no `updated_at` and this model says so, because an activity that
 * could be edited is not a record of anything.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $subject_type
 * @property string $subject_id
 * @property int|null $actor_id
 * @property ActivityType $type
 * @property array<string, mixed> $properties
 * @property CarbonImmutable $created_at
 * @property-read Workspace $workspace
 */
#[UseFactory(ActivityFactory::class)]
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    /**
     * Nothing is fillable. Every activity is written by `RecordActivity` from an event that has
     * already happened, so there is no payload for a caller to hand in.
     */
    protected $guarded = ['*'];

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'properties' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
