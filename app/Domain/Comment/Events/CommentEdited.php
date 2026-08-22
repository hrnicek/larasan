<?php

declare(strict_types=1);

namespace App\Domain\Comment\Events;

final readonly class CommentEdited
{
    public function __construct(
        public string $commentId,
        public string $workspaceId,
        public string $subjectType,
        public string $subjectId,
        public int $editorId,
    ) {}
}
