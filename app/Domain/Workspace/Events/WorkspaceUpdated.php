<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Events;

/**
 * @param  list<string>  $changed  attribute names, so a listener can decide whether it
 *                                 cares without diffing the model itself
 */
final readonly class WorkspaceUpdated
{
    /** @param list<string> $changed */
    public function __construct(
        public string $workspaceId,
        public array $changed,
    ) {}
}
