<?php

declare(strict_types=1);

namespace App\Domain\Task\Exceptions;

use App\Domain\Shared\Exceptions\DomainRefusal;
use DomainException;

/**
 * Invariants the task Actions refuse for every caller. A FormRequest catches most of them
 * first; the Action still checks, because a console command, a queued job or the future
 * API arrives without one.
 */
final class TaskException extends DomainException implements DomainRefusal
{
    public static function cannotCreateTasks(): self
    {
        return new self('You do not have permission to create tasks in this workspace.');
    }

    public static function cannotUpdateTask(): self
    {
        return new self('You do not have permission to change this task.');
    }

    public static function cannotAssignTask(): self
    {
        return new self('You do not have permission to assign this task.');
    }

    public static function cannotDeleteTask(): self
    {
        return new self('You do not have permission to delete this task.');
    }

    public static function cannotBeItsOwnParent(): self
    {
        return new self('A task cannot be a subtask of itself.');
    }

    public static function parentWouldCloseALoop(): self
    {
        return new self('That would make a task a subtask of itself, through its own subtasks.');
    }

    public static function parentChainTooDeep(): self
    {
        return new self('Subtasks cannot be nested that deeply.');
    }

    public static function parentBelongsToAnotherWorkspace(): self
    {
        return new self('A task can only be a subtask of a task in the same workspace.');
    }

    /**
     * Reach, not membership. A guest is a member and still cannot open a task that is in no
     * project of theirs, and work nobody can read is not work anybody can do.
     */
    public static function assigneeCannotReachTask(): self
    {
        return new self('That person cannot reach this task.');
    }

    /**
     * Reach again, this time for notifications: subscribing somebody to a task they cannot
     * open fills an inbox with work nobody can reach (TASK-070-017's rule, applied to
     * watching).
     */
    public static function followerCannotReachTask(): self
    {
        return new self('That person cannot reach this task.');
    }

    /**
     * A star is a shortcut to a task, so it cannot point at one the actor may not open — the same
     * rule as the follower above, applied to a list rather than to an inbox.
     */
    public static function cannotStarUnreachableTask(): self
    {
        return new self('You do not have access to that task.');
    }

    public static function assigneeIsNotAMember(): self
    {
        return new self('A task can only be assigned to an active member of its workspace.');
    }

    public static function collaboratorIsNotAMember(): self
    {
        return new self('A collaborator has to be an active member of the task\'s workspace.');
    }

    /** The rule an assignee is held to, for the people beside them. */
    public static function collaboratorCannotReachTask(): self
    {
        return new self('That person cannot reach this task.');
    }

    public static function collaboratorIsTheAssignee(): self
    {
        return new self('That person is already assigned to this task.');
    }
}
