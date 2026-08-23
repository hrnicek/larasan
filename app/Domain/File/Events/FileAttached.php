<?php

declare(strict_types=1);

namespace App\Domain\File\Events;

/**
 * Ids rather than models, as every domain event here: a queued listener that deserialises a
 * model gets whatever the row looked like when the job ran.
 */
final readonly class FileAttached
{
    public function __construct(
        public string $fileId,
        public string $attachmentId,
        public string $workspaceId,
        public string $subjectType,
        public string $subjectId,
        public int $uploadedById,
    ) {}
}
