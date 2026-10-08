<?php

declare(strict_types=1);

namespace App\Domain\Comment\Data;

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
