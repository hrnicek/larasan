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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

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
            /*
             * The workspaces somebody has been asked to join belong on the screen that
             * answers which workspaces they are in — and it is where the invitation mail
             * lands. Rows already swept to `expired` are left out: there is nothing to
             * answer, and the answer is to ask for a new invitation.
             */
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

    public function create(): Response
    {
        return Inertia::render('workspaces/Create');
    }

    public function store(StoreWorkspaceRequest $request, CreateWorkspace $createWorkspace): RedirectResponse
    {
        $workspace = $createWorkspace->handle(
            $this->actor($request),
            CreateWorkspaceData::fromRequest($request),
        );

        /*
         * The creator lands in the workspace they just made. Without this the settings
         * screen would resolve whichever workspace they were in before, because
         * resolution reads the stored choice and this request never named one.
         */
        $request->user()?->forceFill(['current_workspace_id' => $workspace->id])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace created.')]);

        return to_route('workspaces.edit');
    }

    /**
     * Switching is a redirect, because the work is already done: the resolution
     * middleware refuses a workspace the actor has no active membership in and records
     * the one it resolved. Repeating either here would be a second implementation of the
     * same rule, and the weaker of the two would eventually win.
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

    /**
     * The workspace the resolution middleware already resolved and proved membership
     * for. Re-reading the route parameter here would be a second, weaker check.
     */
    private function current(Request $request): Workspace
    {
        return ResolveCurrentWorkspace::from($request) ?? abort(404);
    }
}
