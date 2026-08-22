<?php

declare(strict_types=1);

namespace App\Domain\Placement\Exceptions;

use DomainException;

/**
 * Invariants the placement Actions refuse for every caller. A FormRequest catches most of
 * them first; the Action still checks, because a console command or a queued job arrives
 * without one.
 */
final class PlacementException extends DomainException
{
    public static function cannotPlaceTasks(): self
    {
        return new self('You do not have permission to change what this project holds.');
    }

    public static function sectionBelongsToAnotherProject(): self
    {
        return new self('That section is not in this project.');
    }

    public static function cardIsNotInThatColumn(): self
    {
        return new self('That card is not in this column.');
    }

    public static function cannotFollowItself(): self
    {
        return new self('A task cannot be placed after itself.');
    }

    /**
     * The rule no foreign key can express: `task_id` and `project_id` each point at a valid
     * row, and the pair is still wrong when the two rows belong to different tenants
     * (ADR-0003).
     */
    public static function taskBelongsToAnotherWorkspace(): self
    {
        return new self('That task is not in this workspace.');
    }
}
