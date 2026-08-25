<?php

declare(strict_types=1);

namespace App\Domain\Task\Models;

use App\Models\User;
use Database\Factories\TaskStarFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's shortcut to one task.
 *
 * Not a follow: following decides who a notification reaches, and starring decides nothing for
 * anybody but the person who did it. The two are separate rows for that reason — a task somebody
 * is watching is rarely the same set as the handful they want at hand.
 *
 * No `workspace_id`: the table is scoped by joining the task that owns it, and a denormalised
 * copy would be the one that drifts (ADR-0005).
 *
 * @property string $id
 * @property string $task_id
 * @property int $user_id
 * @property-read Task $task
 * @property-read User $user
 */
#[UseFactory(TaskStarFactory::class)]
class TaskStar extends Model
{
    /** @use HasFactory<TaskStarFactory> */
    use HasFactory, HasUuids;

    /** A star is created and deleted, never edited. */
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
