<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Data;

use App\Http\Requests\Workspace\UpdateWorkspaceRequest;

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
