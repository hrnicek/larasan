<?php

declare(strict_types=1);

namespace App\Domain\CustomField\Models;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\CustomFieldType;
use App\Domain\Workspace\Models\Workspace;
use Database\Factories\CustomFieldFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something a workspace has decided it wants to record about its work.
 *
 * The **type is not fillable**: it decides which column every value of this field lives in, so
 * changing it would leave the answers people have already given in a column nothing reads.
 * Changing a field's type is a migration of its data, not an edit — and until somebody asks for
 * it, it is not an operation at all.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property CustomFieldType $type
 * @property-read Workspace $workspace
 */
#[UseFactory(CustomFieldFactory::class)]
class CustomField extends Model
{
    /** @use HasFactory<CustomFieldFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['name'];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The choices, in the order the screen draws them.
     *
     * @return HasMany<CustomFieldOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(CustomFieldOption::class)->orderBy('position');
    }

    /**
     * The projects that show this field. The inverse of `Project::customFields()`, and what the
     * settings screen counts to say what a deletion would take off which boards.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_custom_fields');
    }

    /** @return HasMany<TaskCustomFieldValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(TaskCustomFieldValue::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CustomFieldType::class,
        ];
    }
}
