<?php

declare(strict_types=1);

namespace App\Domain\Project\Data;

use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Http\Requests\Project\StoreProjectRequest;
use Carbon\CarbonImmutable;

final readonly class CreateProjectData
{
    public function __construct(
        public string $name,
        public ?string $slug = null,
        public ?string $description = null,
        public ?ProjectColor $color = null,
        public ?string $icon = null,
        public ProjectDefaultView $defaultView = ProjectDefaultView::List,
        public ProjectVisibility $visibility = ProjectVisibility::Workspace,
        public ?CarbonImmutable $startDate = null,
        public ?CarbonImmutable $dueDate = null,
    ) {}

    public static function fromRequest(StoreProjectRequest $request): self
    {
        return new self(
            name: $request->string('name')->toString(),
            slug: $request->string('slug')->value() ?: null,
            description: $request->string('description')->value() ?: null,
            color: $request->enum('color', ProjectColor::class),
            icon: $request->string('icon')->value() ?: null,
            defaultView: $request->enum('default_view', ProjectDefaultView::class) ?? ProjectDefaultView::List,
            visibility: $request->enum('visibility', ProjectVisibility::class) ?? ProjectVisibility::Workspace,
            startDate: $request->date('start_date')?->toImmutable(),
            dueDate: $request->date('due_date')?->toImmutable(),
        );
    }
}
