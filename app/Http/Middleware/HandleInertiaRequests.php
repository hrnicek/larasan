<?php

namespace App\Http\Middleware;

use App\Domain\Workspace\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'workspace' => ($workspace = ResolveCurrentWorkspace::from($request)) === null ? null : [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            /*
             * The switcher lives in the shell, so the list is shared rather than fetched
             * per page. Three columns, active memberships only — the query is the same
             * one the resolution middleware already proved the actor against.
             */
            'workspaces' => $request->user() === null ? [] : $request->user()
                ->workspaces()
                ->orderBy('name')
                ->get(['workspaces.id', 'workspaces.name', 'workspaces.slug'])
                ->map(fn (Workspace $workspace): array => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'slug' => $workspace->slug,
                ])
                ->all(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
