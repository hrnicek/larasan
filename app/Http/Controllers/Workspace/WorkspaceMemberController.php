<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Actions\ChangeWorkspaceMemberRole;
use App\Domain\Workspace\Actions\InviteWorkspaceMember;
use App\Domain\Workspace\Actions\RemoveWorkspaceMember;
use App\Domain\Workspace\Actions\ResendWorkspaceInvitation;
use App\Domain\Workspace\Data\InviteWorkspaceMemberData;
use App\Domain\Workspace\Exceptions\WorkspaceMembershipException;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Domain\Workspace\Queries\WorkspaceMembersQuery;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Http\Requests\Workspace\InviteMemberRequest;
use App\Http\Requests\Workspace\UpdateMemberRoleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceMemberController extends Controller
{
    public function index(Request $request, WorkspaceMembersQuery $members): Response
    {
        $workspace = $this->current($request);

        Gate::authorize('view', $workspace);

        return Inertia::render('settings/Members', [
            ...$members($workspace, $this->actor($request)),
            // Member first as the form's default; Owner is transferred rather than assigned.
            'roles' => [
                WorkspaceRole::Member->value,
                WorkspaceRole::Admin->value,
                WorkspaceRole::Guest->value,
            ],
        ]);
    }

    public function store(InviteMemberRequest $request, InviteWorkspaceMember $invite): RedirectResponse
    {
        $workspace = $this->current($request);

        $this->translating(fn () => $invite->handle(
            $workspace,
            $this->actor($request),
            new InviteWorkspaceMemberData(
                email: $request->string('email')->toString(),
                role: $request->enum('role', WorkspaceRole::class) ?? WorkspaceRole::Member,
            ),
        ), 'email');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('workspaces.members');
    }

    public function update(UpdateMemberRoleRequest $request, string $membership, ChangeWorkspaceMemberRole $changeRole): RedirectResponse
    {
        $workspace = $this->current($request);

        $this->translating(fn () => $changeRole->handle(
            $workspace,
            $this->actor($request),
            $this->membership($workspace, $membership),
            $request->enum('role', WorkspaceRole::class) ?? WorkspaceRole::Member,
        ), 'role');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role updated.')]);

        return to_route('workspaces.members');
    }

    public function resend(Request $request, string $membership, ResendWorkspaceInvitation $resend): RedirectResponse
    {
        $workspace = $this->current($request);

        Gate::authorize(Capability::WorkspaceMembersManage->value, $workspace);

        $this->translating(fn () => $resend->handle(
            $workspace,
            $this->actor($request),
            $this->membership($workspace, $membership),
        ), 'membership');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent again.')]);

        return to_route('workspaces.members');
    }

    public function destroy(Request $request, string $membership, RemoveWorkspaceMember $remove): RedirectResponse
    {
        $workspace = $this->current($request);

        Gate::authorize(Capability::WorkspaceMembersManage->value, $workspace);

        $row = $this->membership($workspace, $membership);

        $wasInvitation = ! $row->status->grantsAccess();

        $this->translating(fn () => $remove->handle($workspace, $this->actor($request), $row), 'membership');

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $wasInvitation ? __('Invitation cancelled.') : __('Member removed.'),
        ]);

        return to_route('workspaces.members');
    }

    /**
     * Resolved through the workspace, so another tenant's id is a 404.
     */
    private function membership(Workspace $workspace, string $id): WorkspaceMembership
    {
        return $workspace->memberships()->whereKey($id)->firstOrFail();
    }

    private function current(Request $request): Workspace
    {
        return ResolveCurrentWorkspace::from($request) ?? abort(404);
    }

    private function translating(callable $operation, string $field): void
    {
        try {
            $operation();
        } catch (WorkspaceMembershipException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }
}
