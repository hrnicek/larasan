<?php

declare(strict_types=1);

namespace App\Domain\Section\Models;

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Casts\AsAccentColor;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Shared\ValueObjects\AccentColor;
use Database\Factories\SectionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property AccentColor|null $color
 * @property int $position
 * @property-read Project $project
 */
#[UseFactory(SectionFactory::class)]
class Section extends Model
{
    /** @use HasFactory<SectionFactory> */
    use HasFactory, HasUuids;

    public const POSITION_GAP = SparsePosition::GAP;

    /**
     * @var list<string>
     */
    public const DEFAULT_NAMES = ['Untitled section'];

    protected $fillable = ['name', 'color', 'position'];

    /**
     * @return HasMany<TaskProjectMembership, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(TaskProjectMembership::class)->orderBy('position');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'color' => AsAccentColor::class,
            'position' => 'integer',
        ];
    }
}
