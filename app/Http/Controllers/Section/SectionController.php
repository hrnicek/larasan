<?php

declare(strict_types=1);

namespace App\Http\Controllers\Section;

use App\Domain\Project\Models\Project;
use App\Domain\Section\Actions\CreateSection;
use App\Domain\Section\Actions\DeleteSection;
use App\Domain\Section\Actions\MoveSection;
use App\Domain\Section\Actions\RenameSection;
use App\Domain\Section\Data\CreateSectionData;
use App\Domain\Section\Data\UpdateSectionData;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectColor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Section\MoveSectionRequest;
use App\Http\Requests\Section\StoreSectionRequest;
use App\Http\Requests\Section\UpdateSectionRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Sections are edited in place on the board and the list, so every method answers with a
 * redirect back to wherever the actor was. There is no section screen to render.
 */
class SectionController extends Controller
{
    public function store(StoreSectionRequest $request, Project $project, CreateSection $createSection): RedirectResponse
    {
        $createSection->handle($project, $this->actor($request), new CreateSectionData(
            name: $request->string('name')->toString(),
            color: $request->enum('color', ProjectColor::class),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Section added.')]);

        return back();
    }

    public function update(UpdateSectionRequest $request, Section $section, RenameSection $renameSection): RedirectResponse
    {
        $renameSection->handle($section, $this->actor($request), new UpdateSectionData(
            name: $request->string('name')->toString(),
            color: $request->enum('color', ProjectColor::class),
        ));

        return back();
    }

    public function move(MoveSectionRequest $request, Section $section, MoveSection $moveSection): RedirectResponse
    {
        $after = $request->string('after')->value() ?: null;

        $moveSection->handle(
            $section,
            $this->actor($request),
            $after === null ? null : $section->project->sections()->whereKey($after)->firstOrFail(),
        );

        return back();
    }

    public function destroy(Request $request, Section $section, DeleteSection $deleteSection): RedirectResponse
    {
        Gate::authorize('delete', $section);

        $deleteSection->handle($section, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Section deleted.')]);

        return back();
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : abort(403);
    }
}
