<?php

declare(strict_types=1);

namespace App\Domain\Comment\Data;

/**
 * A comment body whose mentions were checked, with the names rewritten as they are now.
 */
final readonly class MentionedBody
{
    /**
     * @param  list<int>  $mentionedIds
     */
    public function __construct(
        public string $body,
        public array $mentionedIds,
    ) {}
}
