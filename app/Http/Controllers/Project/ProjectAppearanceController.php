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
     * Back rather than to a named route: the picker is opened from whichever view of the
     * project the reader is on, and `projects.show` without its query string would send
     * a board back to the list.
     *
     * No toast either. The control's own tile is the confirmation, and a message per
     * swatch turns trying colours into a stack of notifications.
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
