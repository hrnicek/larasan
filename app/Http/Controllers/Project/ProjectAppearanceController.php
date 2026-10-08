<?php

declare(strict_types=1);

namespace App\Http\Controllers\Project;

use App\Domain\Project\Actions\UpdateProjectAppearance;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectIcon;
use App\Domain\Shared\ValueObjects\AccentColor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\UpdateProjectAppearanceRequest;
use Illuminate\Http\RedirectResponse;

class ProjectAppearanceController extends Controller
{
    /**
     * Redirects back, since `projects.show` without its query string would lose the current view.
     */
    public function update(
        UpdateProjectAppearanceRequest $request,
        Project $project,
        UpdateProjectAppearance $updateAppearance,
    ): RedirectResponse {
        $updateAppearance->handle(
            $project,
            $this->actor($request),
            AccentColor::tryFrom($request->string('color')->value()),
            $request->enum('icon', ProjectIcon::class),
        );

        return back();
    }
}
