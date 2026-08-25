<?php

declare(strict_types=1);

namespace App\Http\Controllers\Search;

use App\Domain\Search\Queries\GlobalSearchQuery;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Http\Requests\Search\SearchSuggestionsRequest;
use Illuminate\Http\JsonResponse;

/**
 * What the palette asks while somebody is typing.
 *
 * JSON rather than Inertia: the palette is an overlay over whatever screen is already there, and
 * an Inertia visit would replace that screen. The search *screen* is still a screen with an
 * address (`search.index`) — this endpoint is the answer to a keystroke, not to a link.
 */
class SearchSuggestionsController extends Controller
{
    public function __invoke(SearchSuggestionsRequest $request, GlobalSearchQuery $search): JsonResponse
    {
        $workspace = ResolveCurrentWorkspace::from($request);

        if (! $workspace instanceof Workspace) {
            abort(404);
        }

        return response()->json(
            $search($workspace, $this->actor($request), $request->term(), $request->kind()),
        );
    }
}
