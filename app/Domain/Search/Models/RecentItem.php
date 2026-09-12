<?php

declare(strict_types=1);

namespace App\Domain\Search\Models;

use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\RecentItemFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property int $user_id
 * @property string $workspace_id
 * @property string $subject_type
 * @property string $subject_id
 * @property CarbonImmutable $opened_at
 */
#[UseFactory(RecentItemFactory::class)]
class RecentItem extends Model
{
    /** @use HasFactory<RecentItemFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['opened_at' => 'immutable_datetime'];
    }
}
