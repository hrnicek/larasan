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
 * A user-defined grouping inside one project (ADR-0004). The name is content, not a state
 * machine: nothing in the application branches on it, and completion lives on the task
 * rather than being inferred from a column called "Done".
 *
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

    /** The gap ADR-0009 specifies, defined once in `SparsePosition`. */
    public const POSITION_GAP = SparsePosition::GAP;

    /**
     * The columns a new project opens with: one, unnamed in everything but the placeholder
     * it carries until somebody renames it. A project's shape is the team's, not ours —
     * three invented columns are three things to delete before the first real one.
     *
     * Data, not behaviour: nothing in the application may read these back (ADR-0004).
     *
     * @var list<string>
     */
    public const DEFAULT_NAMES = ['Untitled section'];

    protected $fillable = ['name', 'color', 'position'];

    /**
     * The cards in this column, in the order the board draws them. Order means something
     * here and nowhere else in the placement graph (ADR-0009).
     *
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
