<?php

namespace App\Http\Middleware;

use App\Domain\Notification\Queries\InboxQuery;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Shared\Access\MembershipRegistry;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Payloads\PersonSummary;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Every prop here that costs a read is a closure. Inertia resolves a closure only for a
     * response that carries it, and a partial reload — a panel opening, a board refreshing —
     * carries none of the shell's, so each was read on every such request and thrown away.
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
                    ...PersonSummary::from($request->user()),
                    'email_verified_at' => $request->user()->email_verified_at?->toIso8601String(),
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
            'workspaces' => fn (): array => $request->user() === null ? [] : $request->user()
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
            'projects' => fn (): array => $workspace === null || $request->user() === null
                ? []
                : $this->sidebarProjects($workspace, $request->user()),
            /*
             * The shell's unread badge, scoped to the workspace this request resolved. One
             * count, on the index TASK-110-014 built for it — and none at all when nobody is
             * signed in, because a query to answer "zero" is a query nobody needed.
             */
            'unreadNotifications' => fn (): int => $workspace === null || $request->user() === null
                ? 0
                : app(InboxQuery::class)->unreadCount($workspace, $request->user()),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The rows the sidebar draws, each with the two abilities its own menu renders.
     *
     * The abilities are the project policy's answers rather than a second copy of its rules,
     * which costs one `project_memberships` read for the whole list instead of one per row:
     * the memberships are memoised up front and each project is handed the workspace the
     * request already resolved, so no ability check reaches the database.
     *
     * @return list<array<string, mixed>>
     */
    private function sidebarProjects(Workspace $workspace, User $user): array
    {
        $projects = app(VisibleProjectsForUser::class)
            ->query($workspace, $user)
            ->select(['id', 'name', 'slug', 'color', 'icon', 'archived_at'])
            ->withExists(['stars' => fn (Builder $stars): Builder => $stars->where('user_id', $user->id)])
            /*
             * Starred first, and not only because the sidebar draws them in their own group: the
             * list is capped, and a project somebody pinned themselves must not be the one the
             * cap cuts off.
             */
            ->orderByDesc('stars_exists')
            ->orderBy('name')
            ->limit(self::SIDEBAR_PROJECT_LIMIT)
            ->get();

        $projects->each(fn (Project $project) => $project->setRelation('workspace', $workspace));

        app(MembershipRegistry::class)->preloadProjects($projects, $user);

        return array_values($projects
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'color' => $project->color?->value,
                'icon' => $project->icon?->value,
                /*
                 * What the row's context menu draws itself on. The client renders these and
                 * derives neither (ADR-0010); every endpoint behind the menu authorizes again.
                 */
                'canUpdate' => $user->can('update', $project),
                'canArchive' => $user->can('archive', $project),
                /** A star is this actor's own, so it is read per request rather than cached. */
                'starred' => (bool) $project->stars_exists,
            ])
            ->all());
    }
}
