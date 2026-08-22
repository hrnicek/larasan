<?php

declare(strict_types=1);

namespace App\Domain\Task\Data;

use App\Domain\Shared\Enums\TaskPriority;
use Carbon\CarbonImmutable;

/**
 * The workspace and the creator are absent by design: they are arguments to the Action,
 * decided by who is asking and where they are, never payload a request could carry. A
 * field here is a field a form can send.
 *
 * Placement is absent for the same reason it is absent from the table — a task is not
 * created into a project (ADR-0003). Attaching it is Phase 070's operation.
 */
final readonly class CreateTaskData
{
    public function __construct(
        public string $title,
        public ?string $description = null,
        public TaskPriority $priority = TaskPriority::Medium,
        public ?CarbonImmutable $dueAt = null,
        public ?string $parentId = null,
        public ?int $assigneeId = null,
    ) {}
}
