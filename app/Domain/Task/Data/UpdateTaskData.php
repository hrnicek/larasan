<?php

declare(strict_types=1);

namespace App\Domain\Task\Data;

use App\Domain\Shared\Enums\TaskPriority;
use Carbon\CarbonImmutable;

/**
 * Completion and assignment are absent on purpose. Each is its own operation with its own
 * event and its own authorization question, and folding them in here would mean a rename
 * could close a task or hand it to somebody.
 *
 * Null means two things, decided by the column: it clears a nullable one — a description
 * or a due date that no longer applies — and means "unchanged" for `title` and `priority`,
 * which cannot be null at all. Phase 040's review settled that rule after the opposite
 * convention made project fields write-once.
 */
final readonly class UpdateTaskData
{
    public function __construct(
        public string $title,
        public ?string $description = null,
        public ?TaskPriority $priority = null,
        public ?CarbonImmutable $dueAt = null,
        public ?string $parentId = null,
    ) {}
}
