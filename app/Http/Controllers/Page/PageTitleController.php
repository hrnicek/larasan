<?php

declare(strict_types=1);

namespace App\Http\Controllers\Page;

use App\Domain\Page\Actions\RenamePage;
use App\Domain\Page\Models\Page;
use App\Http\Controllers\Controller;
use App\Http\Requests\Page\UpdatePageTitleRequest;
use Illuminate\Http\RedirectResponse;

class PageTitleController extends Controller
{
    public function update(UpdatePageTitleRequest $request, Page $page, RenamePage $renamePage): RedirectResponse
    {
        $renamePage->handle($page, $this->actor($request), $request->string('title')->value());

        return back();
    }
}
