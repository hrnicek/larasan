<?php

declare(strict_types=1);

namespace App\Domain\Comment\Events;

final readonly class CommentDeleted
{
    public function __construct(
        public string $commentId,
        public string $workspaceId,
        public string $subjectType,
        public string $subjectId,
        public int $actorId,
    ) {}
}
