<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Data;

/**
 * The `fromRequest()` named constructor arrives with TASK-020-011, which is where the
 * FormRequest it takes is created. Adding it now would mean typing against a class that
 * does not exist, or against the base Request, which is the framework coupling this
 * object exists to keep out.
 */
final readonly class CreateWorkspaceData
{
    public function __construct(
        public string $name,
        public ?string $slug = null,
        public string $timezone = 'UTC',
    ) {}
}
