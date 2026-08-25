<?php

declare(strict_types=1);

namespace App\Http\Controllers\Page;

use App\Domain\Page\Actions\MovePage;
use App\Domain\Page\Models\Page;
use App\Http\Controllers\Controller;
use App\Http\Requests\Page\MovePageRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Where a page sits in the tree: inside a parent, or at the root, and behind one of its new
 * siblings. Never a position — the client says which page this one follows (ADR-0009).
 */
class PagePlacementController extends Controller
{
    public function update(MovePageRequest $request, Page $page, MovePage $movePage): RedirectResponse
    {
        $movePage->handle(
            $page,
            $this->actor($request),
            $this->siblingOf($page, $request->string('parent')->value() ?: null),
            $this->siblingOf($page, $request->string('after')->value() ?: null),
        );

        return back();
    }

    private function siblingOf(Page $page, ?string $id): ?Page
    {
        return $id === null
            ? null
            : Page::query()->where('project_id', $page->project_id)->findOrFail($id);
    }
}
