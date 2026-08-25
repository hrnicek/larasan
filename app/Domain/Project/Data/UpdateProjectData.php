<?php

declare(strict_types=1);

namespace App\Domain\Project\Data;

use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectIcon;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Http\Requests\Project\UpdateProjectRequest;
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
        public ?ProjectColor $color = null,
        public ?ProjectIcon $icon = null,
        public ?ProjectDefaultView $defaultView = null,
        public ?ProjectVisibility $visibility = null,
        public ?CarbonImmutable $startDate = null,
        public ?CarbonImmutable $dueDate = null,
    ) {}

    public static function fromRequest(UpdateProjectRequest $request): self
    {
        return new self(
            name: $request->string('name')->toString(),
            slug: $request->string('slug')->value() ?: null,
            description: $request->string('description')->value() ?: null,
            color: $request->enum('color', ProjectColor::class),
            icon: $request->enum('icon', ProjectIcon::class),
            defaultView: $request->enum('default_view', ProjectDefaultView::class),
            visibility: $request->enum('visibility', ProjectVisibility::class),
            startDate: $request->date('start_date')?->toImmutable(),
            dueDate: $request->date('due_date')?->toImmutable(),
        );
    }
}
