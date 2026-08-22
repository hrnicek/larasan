<?php

declare(strict_types=1);

namespace App\Domain\Placement\Events;

/**
 * A card changed column, or changed place inside one. Ids rather than models, as in every
 * domain event here; `sectionId` is null for the ungrouped bucket.
 */
final readonly class TaskPlacementMoved
{
    public function __construct(
        public string $placementId,
        public string $taskId,
        public string $projectId,
        public ?string $sectionId,
        public int $movedById,
    ) {}
}
