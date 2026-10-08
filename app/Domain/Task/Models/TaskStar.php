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
