<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Data;

/**
 * Ownership is absent by design. Transferring a workspace is a different operation with
 * a different authorization question (ADR-0010), and a field on this object would let an
 * update request carry it in.
 *
 * `fromRequest()` arrives with TASK-020-011, which creates the FormRequest it takes.
 */
final readonly class UpdateWorkspaceData
{
    public function __construct(
        public string $name,
        public ?string $slug = null,
        public ?string $timezone = null,
    ) {}
}
