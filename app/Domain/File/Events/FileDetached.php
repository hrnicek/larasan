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
        /** True when that was the last thing pointing at the file, which is now soft-deleted. */
        public bool $fileRemoved,
    ) {}
}
