<?php

declare(strict_types=1);

namespace App\Domain\Project\Models;

use App\Models\User;
use Database\Factories\ProjectStarFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $project_id
 * @property int $user_id
 * @property-read Project $project
 * @property-read User $user
 */
#[UseFactory(ProjectStarFactory::class)]
class ProjectStar extends Model
{
    /** @use HasFactory<ProjectStarFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['project_id', 'user_id'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
