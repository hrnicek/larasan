<?php

declare(strict_types=1);

namespace App\Domain\Task\Models;

use App\Models\User;
use Database\Factories\TaskCollaboratorFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody working on a task beside its assignee.
 *
 * The assignee is the one person the task belongs to; a collaborator shares the work without
 * owning it, and a task may have any number of them. The two never overlap: `AddTaskCollaborator`
 * refuses the assignee, and `AssignTask` takes the row away from a collaborator it promotes.
 *
 * No `workspace_id`, for the reason `TaskFollower` has none: the table is scoped by joining the
 * task that owns it (`docs/architecture/database.md`).
 *
 * @property string $id
 * @property string $task_id
 * @property int $user_id
 * @property-read Task $task
 * @property-read User $user
 */
#[UseFactory(TaskCollaboratorFactory::class)]
class TaskCollaborator extends Model
{
    /** @use HasFactory<TaskCollaboratorFactory> */
    use HasFactory, HasUuids;

    /** A collaboration is created and deleted, never edited. */
    public const UPDATED_AT = null;

    protected $fillable = ['task_id', 'user_id'];

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
