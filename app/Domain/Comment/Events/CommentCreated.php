<?php

declare(strict_types=1);

namespace App\Domain\Comment\Events;

/**
 * Ids rather than models, as in every domain event here: a queued listener that deserialises a
 * model gets whatever the row looked like when the job ran.
 */
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
