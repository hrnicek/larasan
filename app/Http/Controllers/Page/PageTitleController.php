<?php

declare(strict_types=1);

namespace App\Http\Controllers\Page;

use App\Domain\Page\Actions\RenamePage;
use App\Domain\Page\Models\Page;
use App\Http\Controllers\Controller;
use App\Http\Requests\Page\UpdatePageTitleRequest;
use Illuminate\Http\RedirectResponse;

/**
 * The title, on an endpoint of its own — the same reason `projects.name.update` has one: the
 * document endpoint takes the whole page, and a rename that went through it would carry an
 * editor's stale copy of the body along with the new name.
 */
class PageTitleController extends Controller
{
    public function update(UpdatePageTitleRequest $request, Page $page, RenamePage $renamePage): RedirectResponse
    {
        $renamePage->handle($page, $this->actor($request), $request->string('title')->value());

        return back();
    }
}
