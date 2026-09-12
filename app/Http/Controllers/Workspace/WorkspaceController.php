<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Workspace\Actions\CreateWorkspace;
use App\Domain\Workspace\Actions\UpdateWorkspace;
use App\Domain\Workspace\Data\CreateWorkspaceData;
use App\Domain\Workspace\Data\UpdateWorkspaceData;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Http\Requests\Workspace\StoreWorkspaceRequest;
use App\Http\Requests\Workspace\UpdateWorkspaceRequest;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InertiaUI\Modal\Modal;

class WorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $request->user();

        return Inertia::render('workspaces/Index', [
            'workspaces' => $actor?->workspaces()
                ->get()
                ->map(fn (Workspace $workspace): array => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'slug' => $workspace->slug,
                ])
                ->all() ?? [],
            'invitations' => $actor?->workspaceMemberships()
                ->with('workspace', 'invitedBy')
                ->where('status', WorkspaceMembershipStatus::Invited->value)
                ->get()
                ->map(fn (WorkspaceMembership $invitation): array => [
                    'id' => $invitation->id,
                    'workspace' => $invitation->workspace->name,
                    'role' => $invitation->role->value,
                    'invitedBy' => $invitation->invitedBy->name ?? null,
                    'expiresAt' => $invitation->expires_at?->toIso8601String(),
                    'hasExpired' => $invitation->hasExpired(),
                ])
                ->all() ?? [],
        ]);
    }

    public function create(): Modal
    {
        return Inertia::modal('workspaces/Create', [
            // The list the `timezone` rule validates against; browsers still report legacy aliases.
            'options' => [
                'timezones' => DateTimeZone::listIdentifiers(),
            ],
        ])->baseRoute('workspaces.index');
    }

    public function store(StoreWorkspaceRequest $request, CreateWorkspace $createWorkspace): RedirectResponse
    {
        $workspace = $createWorkspace->handle(
            $this->actor($request),
            CreateWorkspaceData::fromRequest($request),
        );

        // The redirect resolves the stored workspace, so point it at the new one first.
        $request->user()?->forceFill(['current_workspace_id' => $workspace->id])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace created.')]);

        return to_route('workspaces.edit');
    }

    /**
     * The resolution middleware has already validated and stored the workspace.
     */
    public function switch(Request $request): RedirectResponse
    {
        $this->current($request);

        return to_route('dashboard');
    }

    public function edit(Request $request): Response
    {
        $workspace = $this->current($request);

        Gate::authorize('view', $workspace);

        return Inertia::render('settings/Workspace', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'timezone' => $workspace->timezone,
            ],
            'can' => [
                'update' => $request->user()?->can('update', $workspace) ?? false,
                'delete' => $request->user()?->can('delete', $workspace) ?? false,
            ],
        ]);
    }

    public function update(UpdateWorkspaceRequest $request, UpdateWorkspace $updateWorkspace): RedirectResponse
    {
        $updateWorkspace->handle(
            $this->current($request),
            UpdateWorkspaceData::fromRequest($request),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace updated.')]);

        return to_route('workspaces.edit');
    }

    private function current(Request $request): Workspace
    {
        return ResolveCurrentWorkspace::from($request) ?? abort(404);
    }
}
