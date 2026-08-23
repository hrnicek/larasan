<?php

declare(strict_types=1);

namespace App\Domain\CustomField\Models;

use App\Domain\Project\Models\Project;
use Database\Factories\ProjectCustomFieldFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A field a project has chosen to show. A workspace can define more than any one project wants
 * on its screen, so attaching is a decision per project rather than a consequence of defining.
 *
 * @property string $id
 * @property string $project_id
 * @property string $custom_field_id
 * @property int $position
 */
#[UseFactory(ProjectCustomFieldFactory::class)]
class ProjectCustomField extends Model
{
    /** @use HasFactory<ProjectCustomFieldFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['position'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<CustomField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}
