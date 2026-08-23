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
 * Access level inside one project. It answers only what this person may do *here*; the
 * workspace capability answers whether they may do that kind of thing at all, and both
 * must pass where both apply (ADR-0006, ADR-0010).
 *
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
     * A project membership only means something inside the workspace that owns the project.
     * Enforced here rather than in one Action because these rows are written from several
     * places — project creation, membership management, a seeder — and an invariant that
     * depends on remembering to check it is one that eventually is not checked.
     *
     * The database cannot express it: the check spans `projects` and `workspace_memberships`,
     * and PostgreSQL's `CHECK` cannot see another table (ADR-0005 records the same limit for
     * cross-aggregate references).
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
