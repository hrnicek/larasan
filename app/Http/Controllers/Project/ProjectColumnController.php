<?php

declare(strict_types=1);

namespace App\Http\Controllers\Project;

use App\Domain\Project\Actions\ReorderProjectColumns;
use App\Domain\Project\Models\Project;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ReorderProjectColumnsRequest;
use Illuminate\Http\RedirectResponse;

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
