<?php

declare(strict_types=1);

namespace App\Domain\File\Exceptions;

use App\Domain\Shared\Exceptions\DomainRefusal;
use DomainException;

final class FileException extends DomainException implements DomainRefusal
{
    public static function cannotUpload(): self
    {
        return new self('You do not have permission to upload files in this workspace.');
    }

    public static function cannotReachSubject(): self
    {
        return new self('You cannot attach a file to something you cannot reach.');
    }

    public static function couldNotStore(): self
    {
        return new self('That file could not be stored. Nothing was attached.');
    }

    public static function cannotRemoveAttachment(): self
    {
        return new self('You do not have permission to remove this attachment.');
    }

    public static function cannotReorderAttachments(): self
    {
        return new self('You do not have permission to reorder these files.');
    }

    public static function attachmentBelongsToAnotherSubject(): self
    {
        return new self('That file is attached to something else.');
    }

    public static function cannotFollowItself(): self
    {
        return new self('A file cannot be placed after itself.');
    }
}
