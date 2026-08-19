<?php

declare(strict_types=1);

namespace App\Domain\Project\Data;

use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectVisibility;
use Carbon\CarbonImmutable;

/**
 * Ownership and workspace are absent by design: moving a project between workspaces would
 * strand every task in it, and transferring it is a different operation with a different
 * authorization question.
 */
final readonly class UpdateProjectData
{
    public function __construct(
        public string $name,
        public ?string $slug = null,
        public ?string $description = null,
        public ?string $color = null,
        public ?string $icon = null,
        public ?ProjectDefaultView $defaultView = null,
        public ?ProjectVisibility $visibility = null,
        public ?CarbonImmutable $startDate = null,
        public ?CarbonImmutable $dueDate = null,
    ) {}
}
