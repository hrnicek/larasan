<?php

declare(strict_types=1);

namespace App\Domain\Project\Data;

use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectVisibility;
use Carbon\CarbonImmutable;

final readonly class CreateProjectData
{
    public function __construct(
        public string $name,
        public ?string $slug = null,
        public ?string $description = null,
        public ?string $color = null,
        public ?string $icon = null,
        public ProjectDefaultView $defaultView = ProjectDefaultView::List,
        public ProjectVisibility $visibility = ProjectVisibility::Workspace,
        public ?CarbonImmutable $startDate = null,
        public ?CarbonImmutable $dueDate = null,
    ) {}
}
