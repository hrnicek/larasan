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
     * Props that cost a query are closures, so a partial reload does not resolve them.
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
                // Explicit fields, never the model, so a column added later is not exposed by accident.
                'user' => $request->user() === null ? null : [
                    ...PersonSummary::from($request->user()),
                    'email_verified_at' => $request->user()->email_verified_at?->toIso8601String(),
                ],
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
            'projects' => fn (): array => $workspace === null || $request->user() === null
                ? []
                : $this->sidebarProjects($workspace, $request->user()),
            'unreadNotifications' => fn (): int => $workspace === null || $request->user() === null
                ? 0
                : app(InboxQuery::class)->unreadCount($workspace, $request->user()),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Preloads memberships and sets the workspace relation so the ability checks run without queries.
     *
     * @return list<array<string, mixed>>
     */
    private function sidebarProjects(Workspace $workspace, User $user): array
    {
        $projects = app(VisibleProjectsForUser::class)
            ->query($workspace, $user)
            ->select(['id', 'name', 'slug', 'color', 'icon', 'archived_at'])
            ->withExists(['stars' => fn (Builder $stars): Builder => $stars->where('user_id', $user->id)])
            // Starred first, so the cap never cuts off a starred project.
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
                'canUpdate' => $user->can('update', $project),
                'canArchive' => $user->can('archive', $project),
                'starred' => (bool) $project->stars_exists,
            ])
            ->all());
    }
}
