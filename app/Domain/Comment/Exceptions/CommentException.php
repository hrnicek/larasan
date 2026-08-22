<?php

declare(strict_types=1);

namespace App\Domain\Comment\Exceptions;

use App\Domain\Shared\Exceptions\DomainRefusal;
use DomainException;

/**
 * Invariants the comment Actions refuse for every caller. A FormRequest catches most of them
 * first; the Action still checks, because a console command or a queued job arrives without
 * one.
 */
final class CommentException extends DomainException implements DomainRefusal
{
    public static function cannotComment(): self
    {
        return new self('You do not have permission to comment in this workspace.');
    }

    /**
     * Reach, once more. Reading a task is what makes commenting on it possible: somebody who
     * cannot open the subject cannot say anything about it either (TASK-070-017).
     */
    public static function cannotReachSubject(): self
    {
        return new self('You cannot comment on something you cannot reach.');
    }

    public static function bodyIsEmpty(): self
    {
        return new self('A comment needs something in it.');
    }

    public static function notTheAuthor(): self
    {
        return new self('Only the author can edit a comment.');
    }

    public static function cannotDeleteComment(): self
    {
        return new self('You do not have permission to delete this comment.');
    }
}
