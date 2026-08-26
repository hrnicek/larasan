<?php

declare(strict_types=1);

namespace App\Http\Controllers\Project;

use App\Domain\Project\Actions\ReorderProjectColumns;
use App\Domain\Project\Models\Project;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ReorderProjectColumnsRequest;
use Illuminate\Http\RedirectResponse;

/**
 * The order the list draws its columns in.
 *
 * A `PUT` of the whole order rather than a move endpoint: the list is short, it is read as one
 * thing, and a request that could apply half an order is one nobody wants to have sent — the same
 * reasoning `CustomFieldOptionController` writes down for a choice field's choices.
 */
class ProjectColumnController extends Controller
{
    public function update(
        ReorderProjectColumnsRequest $request,
        Project $project,
        ReorderProjectColumns $reorderColumns,
    ): RedirectResponse {
        /** @var list<string> $columns */
        $columns = $request->validated('columns');

        $reorderColumns->handle($project, $this->actor($request), $columns);

        return back();
    }
}
