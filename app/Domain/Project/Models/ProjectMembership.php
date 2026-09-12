<?php

declare(strict_types=1);

namespace App\Domain\Project\Models;

use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Models\User;
use Database\Factories\ProjectMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $project_id
 * @property int $user_id
 * @property ProjectAccessLevel $access_level
 * @property-read Project $project
 * @property-read User $user
 */
#[UseFactory(ProjectMembershipFactory::class)]
class ProjectMembership extends Model
{
    /** @use HasFactory<ProjectMembershipFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['project_id', 'user_id', 'access_level'];

    /**
     * Enforced on the model because the rule spans tables, which a CHECK constraint cannot express.
     * See ADR-0005.
     */
    protected static function booted(): void
    {
        static::creating(function (ProjectMembership $membership): void {
            $project = $membership->project ?? Project::query()->find($membership->project_id);

            if ($project instanceof Project && ! $project->workspace->hasActiveMember($membership->user_id)) {
                throw ProjectException::memberIsNotInTheWorkspace();
            }
        });
    }

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
