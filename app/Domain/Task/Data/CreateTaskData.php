<?php

declare(strict_types=1);

namespace App\Domain\Task\Data;

use App\Domain\Shared\Enums\TaskPriority;
use App\Http\Requests\Task\StoreTaskRequest;
use Carbon\CarbonImmutable;

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

    public static function fromRequest(StoreTaskRequest $request): self
    {
        return new self(
            title: $request->string('title')->toString(),
            description: $request->string('description')->value() ?: null,
            priority: $request->enum('priority', TaskPriority::class) ?? TaskPriority::Medium,
            dueAt: $request->date('due_at')?->toImmutable(),
            parentId: $request->string('parent_id')->value() ?: null,
            assigneeId: $request->integer('assignee_id') ?: null,
        );
    }

    public function withoutAssignee(): self
    {
        return new self(
            title: $this->title,
            description: $this->description,
            priority: $this->priority,
            dueAt: $this->dueAt,
            parentId: $this->parentId,
        );
    }
}
