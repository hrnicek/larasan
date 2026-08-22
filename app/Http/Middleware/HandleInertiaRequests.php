<?php

namespace App\Http\Middleware;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The sidebar shows the projects someone works in, not every project they may open.
     * Past this many the list stops being navigation, and `projects.index` is one click
     * away — which is also what keeps this prop from growing with the workspace on every
     * single request.
     */
    private const SIDEBAR_PROJECT_LIMIT = 15;

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
        $workspace = ResolveCurrentWorkspace::from($request);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                /*
                 * Explicitly listed, never the model. `$request->user()` ships whatever
                 * columns the table has, so every column added later becomes a public
                 * API by accident and `#[Hidden]` is the only thing in the way.
                 */
                'user' => $request->user() === null ? null : [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'email_verified_at' => $request->user()->email_verified_at?->toIso8601String(),
                    'avatar' => null,
                ],
                /*
                 * What the actor may do in the workspace this request resolved, as the
                 * enum's string values. The client renders these; it never derives a
                 * permission from a role name (ADR-0010).
                 */
                'capabilities' => $workspace === null || $request->user() === null
                    ? []
                    : array_map(
                        fn (Capability $capability): string => $capability->value,
                        $workspace->membershipFor($request->user())?->status->grantsAccess() === true
                            ? $workspace->membershipFor($request->user())->role->capabilities()
                            : [],
                    ),
            ],
            'workspace' => $workspace === null ? null : [
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
            /*
             * The sidebar list, resolved through the same query the endpoints use: the
             * projects the actor may see in this workspace, archived ones excluded. Asking
             * the model per row here would be an N+1 on every request in the application.
             */
            'projects' => $workspace === null || $request->user() === null ? [] : app(VisibleProjectsForUser::class)
                ->query($workspace, $request->user())
                ->orderBy('name')
                ->limit(self::SIDEBAR_PROJECT_LIMIT)
                ->get(['id', 'name', 'slug', 'color', 'icon'])
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'slug' => $project->slug,
                    'color' => $project->color?->value,
                    'icon' => $project->icon,
                ])
                ->all(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
