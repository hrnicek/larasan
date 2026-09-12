<?php

declare(strict_types=1);

namespace App\Domain\File\Events;

final readonly class FileDetached
{
    public function __construct(
        public string $fileId,
        public string $workspaceId,
        public string $subjectType,
        public string $subjectId,
        public int $actorId,
        /** The file had no other attachments and was soft-deleted. */
        public bool $fileRemoved,
    ) {}
}
