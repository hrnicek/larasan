<?php

declare(strict_types=1);

namespace App\Http\Controllers\Search;

use App\Domain\Search\Queries\GlobalSearchQuery;
use App\Domain\Search\Queries\RecentItemsForUser;
use App\Domain\Search\Queries\SavedSearchesForUser;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Http\Requests\Search\SearchSuggestionsRequest;
use Illuminate\Http\JsonResponse;

class SearchSuggestionsController extends Controller
{
    public function __invoke(
        SearchSuggestionsRequest $request,
        GlobalSearchQuery $search,
        SavedSearchesForUser $saved,
        RecentItemsForUser $recents,
    ): JsonResponse {
        $workspace = ResolveCurrentWorkspace::from($request);

        if (! $workspace instanceof Workspace) {
            abort(404);
        }

        $actor = $this->actor($request);
        $term = $request->term();

        return response()->json([
            ...$search($workspace, $actor, $term, $request->kind()),
            'saved' => $term === '' ? $saved($workspace, $actor) : [],
            'recents' => $term === '' ? $recents($workspace, $actor) : [],
        ]);
    }
}
