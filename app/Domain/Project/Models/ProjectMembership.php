<?php

declare(strict_types=1);

namespace App\Domain\Project\Models;

use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Models\User;
use Database\Factories\ProjectMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Access level inside one project. It answers only what this person may do *here*; the
 * workspace capability answers whether they may do that kind of thing at all, and both
 * must pass where both apply (ADR-0006, ADR-0010).
 *
 * @property string $id
 * @property string $project_id
 * @property int $user_id
 * @property ProjectAccessLevel $access_level
 */
#[UseFactory(ProjectMembershipFactory::class)]
class ProjectMembership extends Model
{
    /** @use HasFactory<ProjectMembershipFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['project_id', 'user_id', 'access_level'];

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'access_level' => ProjectAccessLevel::class,
        ];
    }
}
