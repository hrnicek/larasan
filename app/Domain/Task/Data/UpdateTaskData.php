<?php

declare(strict_types=1);

namespace App\Domain\Task\Data;

use App\Domain\Shared\Enums\TaskPriority;
use App\Http\Requests\Task\UpdateTaskRequest;
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
 *
 * `$fields` is the third state that rule needs once a row can edit one field at a time: a
 * field the payload never mentioned. Without it, a due-date picker that sends a date and
 * nothing else would clear the description, because null on a nullable column means clear.
 * Constructed directly, every field is present — the callers that build one by hand mean
 * all of it.
 */
final readonly class UpdateTaskData
{
    /** @var list<string> */
    public const EVERY_FIELD = ['title', 'description', 'priority', 'due_at', 'parent_id'];

    /**
     * @param  list<string>  $fields  the payload's own keys: what a null is allowed to mean
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
