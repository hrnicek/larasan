<?php

declare(strict_types=1);

namespace App\Http\Controllers\Project;

use App\Domain\Project\Actions\SetProjectDefaultView;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\UpdateProjectDefaultViewRequest;
use Illuminate\Http\RedirectResponse;

class ProjectDefaultViewController extends Controller
{
    public function update(
        UpdateProjectDefaultViewRequest $request,
        Project $project,
        SetProjectDefaultView $setProjectDefaultView,
    ): RedirectResponse {
        $setProjectDefaultView->handle(
            $project,
            $this->actor($request),
            $request->enum('default_view', ProjectDefaultView::class) ?? ProjectDefaultView::List,
        );

        return back();
    }
}
