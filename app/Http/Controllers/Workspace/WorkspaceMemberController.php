<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Actions\ChangeWorkspaceMemberRole;
use App\Domain\Workspace\Actions\InviteWorkspaceMember;
use App\Domain\Workspace\Actions\RemoveWorkspaceMember;
use App\Domain\Workspace\Actions\ResendWorkspaceInvitation;
use App\Domain\Workspace\Data\InviteWorkspaceMemberData;
use App\Domain\Workspace\Exceptions\WorkspaceMembershipException;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
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
    public function index(Request $request): Response
    {
        $workspace = $this->current($request);

        Gate::authorize('view', $workspace);

        $canManage = $request->user()?->can(Capability::WorkspaceMembersManage->value, $workspace) ?? false;

        /*
         * Counted once rather than per row: `isLastOwner()` is a query, and the screen is
         * open to every member. It is also the only reason the list needs to know about
         * owners at all.
         */
        $activeOwners = $workspace->memberships()
            ->where('role', WorkspaceRole::Owner->value)
            ->where('status', WorkspaceMembershipStatus::Active->value)
            ->count();

        return Inertia::render('settings/Members', [
            'members' => $workspace->memberships()
                ->with('user')
                ->where('status', WorkspaceMembershipStatus::Active->value)
                ->orderBy('created_at')
                ->get()
                ->map(fn (WorkspaceMembership $membership): array => [
                    'id' => $membership->id,
                    // An active row always names an account — an unclaimed one can only be an
                    // invitation — and the address is what is left if that ever stops being true.
                    'name' => $membership->user->name ?? $membership->address(),
                    /*
                     * A guest is an outside collaborator (ADR-0006); handing them every
                     * colleague's address is not part of commenting on a task. Managers
                     * need it to tell two people apart and to know who they invited.
                     */
                    'email' => $canManage || $membership->user_id === $request->user()?->id
                        ? $membership->address()
                        : null,
                    'role' => $membership->role->value,
                    'joinedAt' => $membership->joined_at?->toIso8601String(),
                    'isYou' => $membership->user_id === $request->user()?->id,
                    'isLastOwner' => $membership->role->isOwner() && $activeOwners === 1,
                ])
                ->all(),
            /*
             * Only for somebody who can act on them. An invitation is a management matter, and
             * it is mostly an address — which this screen deliberately withholds from everybody
             * else. Declined and revoked rows are absent from both lists: they are history, and
             * bringing somebody back is the invite form's job rather than a button on a row.
             */
            'invitations' => $canManage
                ? $workspace->memberships()
                    ->with('user', 'invitedBy')
                    ->whereIn('status', [
                        WorkspaceMembershipStatus::Invited->value,
                        WorkspaceMembershipStatus::Expired->value,
                    ])
                    ->orderBy('created_at')
                    ->get()
                    ->map(fn (WorkspaceMembership $invitation): array => [
                        'id' => $invitation->id,
                        'email' => $invitation->address(),
                        'name' => $invitation->user->name ?? null,
                        'role' => $invitation->role->value,
                        'invitedBy' => $invitation->invitedBy->name ?? null,
                        'expiresAt' => $invitation->expires_at?->toIso8601String(),
                        // The sweep runs on a schedule, so a row can be past its deadline and
                        // still say `invited`. The screen answers for the deadline, not the column.
                        'hasExpired' => $invitation->hasExpired()
                            || $invitation->status === WorkspaceMembershipStatus::Expired,
                        'hasAccount' => $invitation->isClaimed(),
                    ])
                    ->all()
                : [],
            /*
             * Member first, because it is the answer most invitations want and the form
             * offers the first option by default. Owner is absent: it is transferred, not
             * assigned.
             */
            'roles' => [
                WorkspaceRole::Member->value,
                WorkspaceRole::Admin->value,
                WorkspaceRole::Guest->value,
            ],
            'can' => [
                'manageMembers' => $canManage,
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

        // Read before the row is acted on: taking back an invitation and removing somebody who
        // is here are the same endpoint and not the same sentence.
        $wasInvitation = ! $row->status->grantsAccess();

        $this->translating(fn () => $remove->handle($workspace, $this->actor($request), $row), 'membership');

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $wasInvitation ? __('Invitation cancelled.') : __('Member removed.'),
        ]);

        return to_route('workspaces.members');
    }

    /**
     * The membership is looked up **through the workspace**, so an id belonging to another
     * tenant is a 404 rather than a permission error — the same answer an id that does not
     * exist gets.
     */
    private function membership(Workspace $workspace, string $id): WorkspaceMembership
    {
        return $workspace->memberships()->whereKey($id)->firstOrFail();
    }

    private function current(Request $request): Workspace
    {
        return ResolveCurrentWorkspace::from($request) ?? abort(404);
    }

    /**
     * Domain refusals become validation errors on the field the user can act on. The
     * Action does not know it is being called over HTTP, and this is the only place that
     * does.
     */
    private function translating(callable $operation, string $field): void
    {
        try {
            $operation();
        } catch (WorkspaceMembershipException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }
}
