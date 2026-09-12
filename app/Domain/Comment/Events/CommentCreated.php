<?php

declare(strict_types=1);

namespace App\Domain\Comment\Events;

final readonly class CommentCreated
{
    /**
     * @param  list<int>  $mentionedIds  the people the body names, already checked
     */
    public function __construct(
        public string $commentId,
        public string $workspaceId,
        public string $subjectType,
        public string $subjectId,
        public int $authorId,
        public array $mentionedIds = [],
    ) {}
}
