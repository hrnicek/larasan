<?php

declare(strict_types=1);

namespace App\Http\Controllers\Search;

use App\Domain\Search\Actions\DeleteSavedSearch;
use App\Domain\Search\Actions\SaveSearch;
use App\Domain\Search\Data\SaveSearchData;
use App\Domain\Search\Exceptions\SavedSearchException;
use App\Domain\Search\Models\SavedSearch;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Http\Requests\Search\StoreSavedSearchRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class SavedSearchController extends Controller
{
    public function store(StoreSavedSearchRequest $request, SaveSearch $save): RedirectResponse
    {
        $workspace = ResolveCurrentWorkspace::from($request);

        if (! $workspace instanceof Workspace) {
            abort(404);
        }

        try {
            $save->handle($workspace, $this->actor($request), SaveSearchData::fromRequest($request));
        } catch (SavedSearchException $refused) {
            return back()->withErrors(['name' => $refused->getMessage()]);
        }

        return back();
    }

    public function destroy(SavedSearch $savedSearch, DeleteSavedSearch $delete): RedirectResponse
    {
        Gate::authorize('delete', $savedSearch);

        $delete->handle($savedSearch);

        return back();
    }
}
