<?php

declare(strict_types=1);

namespace App\Domain\Search\Models;

use App\Domain\Shared\Enums\SearchKind;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\SavedSearchFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A search somebody kept.
 *
 * @property string $id
 * @property int $user_id
 * @property string $workspace_id
 * @property string $name
 * @property string $term
 * @property SearchKind|null $kind
 * @property array<string, mixed> $filters
 * @property CarbonImmutable|null $created_at
 */
#[UseFactory(SavedSearchFactory::class)]
class SavedSearch extends Model
{
    /** @use HasFactory<SavedSearchFactory> */
    use HasFactory, HasUuids;

    /**
     * The owner and the workspace are absent: they are decided by who is asking and where they
     * are, never by a payload.
     */
    protected $fillable = ['name', 'term', 'kind', 'filters'];

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
        return [
            'kind' => SearchKind::class,
            'filters' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
