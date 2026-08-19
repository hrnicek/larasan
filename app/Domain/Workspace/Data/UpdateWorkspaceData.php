<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Data;

use App\Http\Requests\Workspace\UpdateWorkspaceRequest;

/**
 * Ownership is absent by design. Transferring a workspace is a different operation with
 * a different authorization question (ADR-0010), and a field on this object would let an
 * update request carry it in.
 */
final readonly class UpdateWorkspaceData
{
    public function __construct(
        public string $name,
        public ?string $slug = null,
        public ?string $timezone = null,
    ) {}

    public static function fromRequest(UpdateWorkspaceRequest $request): self
    {
        return new self(
            name: $request->string('name')->toString(),
            slug: $request->string('slug')->value() ?: null,
            timezone: $request->string('timezone')->value() ?: null,
        );
    }
}
