<?php

declare(strict_types=1);

namespace App\Domain\Comment\Events;

final readonly class CommentEdited
{
    /**
     * @param  list<int>  $mentionedIds  everybody the edited body names, not only the new names
     */
    public function __construct(
        public string $commentId,
        public string $workspaceId,
        public string $subjectType,
        public string $subjectId,
        public int $editorId,
        public array $mentionedIds = [],
    ) {}
}
