<?php

declare(strict_types=1);

namespace App\Http\Controllers\Search;

use App\Domain\Search\Queries\GlobalSearchQuery;
use App\Domain\Search\Queries\SavedSearchesForUser;
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
    public function __invoke(
        SearchSuggestionsRequest $request,
        GlobalSearchQuery $search,
        SavedSearchesForUser $saved,
    ): JsonResponse {
        $workspace = ResolveCurrentWorkspace::from($request);

        if (! $workspace instanceof Workspace) {
            abort(404);
        }

        $actor = $this->actor($request);
        $term = $request->term();

        return response()->json([
            ...$search($workspace, $actor, $term, $request->kind()),
            /*
             * Only on the empty field, which is where they are drawn. Sending them beside every
             * keystroke's results would be a second query per letter for a row nobody is looking
             * at while they type.
             */
            'saved' => $term === '' ? $saved($workspace, $actor) : [],
        ]);
    }
}
