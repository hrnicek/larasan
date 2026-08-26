<?php

declare(strict_types=1);

namespace App\Domain\File\Exceptions;

use App\Domain\Shared\Exceptions\DomainRefusal;
use DomainException;

/**
 * Invariants the file Actions refuse for every caller. A FormRequest catches most of them
 * first; the Action still checks, because a console command or a queued job arrives without one.
 */
final class FileException extends DomainException implements DomainRefusal
{
    public static function cannotUpload(): self
    {
        return new self('You do not have permission to upload files in this workspace.');
    }

    /**
     * Reach again. Attaching a file to something somebody cannot open is putting a document
     * somewhere they will never see it (TASK-070-017).
     */
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

    /**
     * The anchor of a move has to hang from the same thing. Ordering is a property of one
     * subject's list, so an attachment from somewhere else has no slot in it.
     */
    public static function attachmentBelongsToAnotherSubject(): self
    {
        return new self('That file is attached to something else.');
    }

    public static function cannotFollowItself(): self
    {
        return new self('A file cannot be placed after itself.');
    }
}
