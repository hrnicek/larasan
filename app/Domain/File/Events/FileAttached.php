<?php

declare(strict_types=1);

namespace App\Domain\File\Events;

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
