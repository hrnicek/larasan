<?php

declare(strict_types=1);

namespace App\Domain\Section\Models;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Ordering\SparsePosition;
use Database\Factories\SectionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user-defined grouping inside one project (ADR-0004). The name is content, not a state
 * machine: nothing in the application branches on it, and completion lives on the task
 * rather than being inferred from a column called "Done".
 *
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property ProjectColor|null $color
 * @property int $position
 */
#[UseFactory(SectionFactory::class)]
class Section extends Model
{
    /** @use HasFactory<SectionFactory> */
    use HasFactory, HasUuids;

    /** The gap ADR-0009 specifies, defined once in `SparsePosition`. */
    public const POSITION_GAP = SparsePosition::GAP;

    protected $fillable = ['name', 'color', 'position'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'color' => ProjectColor::class,
            'position' => 'integer',
        ];
    }
}
