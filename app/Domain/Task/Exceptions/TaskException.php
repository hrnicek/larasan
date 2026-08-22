<?php

declare(strict_types=1);

namespace App\Domain\Task\Exceptions;

use DomainException;

/**
 * Invariants the task Actions refuse for every caller. A FormRequest catches most of them
 * first; the Action still checks, because a console command, a queued job or the future
 * API arrives without one.
 */
final class TaskException extends DomainException
{
    public static function cannotCreateTasks(): self
    {
        return new self('You do not have permission to create tasks in this workspace.');
    }

    public static function cannotUpdateTask(): self
    {
        return new self('You do not have permission to change this task.');
    }

    public static function cannotBeItsOwnParent(): self
    {
        return new self('A task cannot be a subtask of itself.');
    }

    public static function parentBelongsToAnotherWorkspace(): self
    {
        return new self('A task can only be a subtask of a task in the same workspace.');
    }

    public static function assigneeIsNotAMember(): self
    {
        return new self('A task can only be assigned to an active member of its workspace.');
    }
}
