<?php

declare(strict_types=1);

namespace App\Domain\Task\Data;

use App\Domain\Shared\Enums\TaskPriority;
use App\Http\Requests\Task\UpdateTaskRequest;
use Carbon\CarbonImmutable;

final readonly class UpdateTaskData
{
    /** @var list<string> */
    public const EVERY_FIELD = ['title', 'description', 'priority', 'due_at', 'parent_id'];

    /**
     * @param  list<string>  $fields  the keys present in the payload
     */
    public function __construct(
        public string $title = '',
        public ?string $description = null,
        public ?TaskPriority $priority = null,
        public ?CarbonImmutable $dueAt = null,
        public ?string $parentId = null,
        public array $fields = self::EVERY_FIELD,
    ) {}

    public function changes(string $field): bool
    {
        return in_array($field, $this->fields, strict: true);
    }

    public static function fromRequest(UpdateTaskRequest $request): self
    {
        return new self(
            title: $request->string('title')->toString(),
            description: $request->string('description')->value() ?: null,
            priority: $request->enum('priority', TaskPriority::class),
            dueAt: $request->date('due_at')?->toImmutable(),
            parentId: $request->string('parent_id')->value() ?: null,
            fields: array_values(array_filter(
                self::EVERY_FIELD,
                fn (string $field): bool => $request->exists($field),
            )),
        );
    }
}
