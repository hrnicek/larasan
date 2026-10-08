<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Data;

use App\Http\Requests\Workspace\StoreWorkspaceRequest;

final readonly class CreateWorkspaceData
{
    public function __construct(
        public string $name,
        public ?string $slug = null,
        public string $timezone = 'UTC',
    ) {}

    public static function fromRequest(StoreWorkspaceRequest $request): self
    {
        return new self(
            name: $request->string('name')->toString(),
            slug: $request->string('slug')->value() ?: null,
            timezone: $request->string('timezone')->value() ?: 'UTC',
        );
    }
}
