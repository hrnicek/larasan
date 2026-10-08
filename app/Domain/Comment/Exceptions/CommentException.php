<?php

declare(strict_types=1);

namespace App\Domain\Comment\Exceptions;

use App\Domain\Comment\Support\Mentions;
use App\Domain\Shared\Exceptions\DomainRefusal;
use DomainException;

final class CommentException extends DomainException implements DomainRefusal
{
    public static function cannotComment(): self
    {
        return new self('You do not have permission to comment in this workspace.');
    }

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

    /** One message for every refusal, so the response cannot confirm that an account exists. */
    public static function cannotMention(): self
    {
        return new self('Only people who can see this task can be mentioned on it.');
    }

    public static function tooManyMentions(): self
    {
        return new self(sprintf('A comment can mention at most %d people.', Mentions::LIMIT));
    }
}
