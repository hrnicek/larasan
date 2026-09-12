<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Workspace\Actions\AnswerWorkspaceInvitation;
use App\Domain\Workspace\Actions\ClaimWorkspaceInvitations;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WorkspaceInvitationController extends Controller
{
    public function show(Request $request, string $membership, ClaimWorkspaceInvitations $claim): RedirectResponse
    {
        $invitation = WorkspaceMembership::query()->whereKey($membership)->firstOrFail();
        $address = $invitation->address();
        $actor = $request->user();

        if (! $actor instanceof User) {
            return $this->towardsAnAccount($request, $address);
        }

        if (mb_strtolower($actor->email) === mb_strtolower($address)) {
            $claim->handle($actor);

            return to_route('workspaces.index');
        }

        Inertia::flash('toast', [
            'type' => 'error',
            'message' => __('That invitation was sent to :address, and you are signed in as :actor.', [
                'address' => $address,
                'actor' => $actor->email,
            ]),
        ]);

        return to_route('workspaces.index');
    }

    public function accept(Request $request, string $membership, AnswerWorkspaceInvitation $answer): RedirectResponse
    {
        $actor = $this->actor($request);
        $invitation = $this->pending($actor, $membership);

        $answer->accept($invitation, $actor);

        $actor->forceFill(['current_workspace_id' => $invitation->workspace_id])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('You joined :workspace.', ['workspace' => $invitation->workspace->name]),
        ]);

        return to_route('dashboard');
    }

    public function decline(Request $request, string $membership, AnswerWorkspaceInvitation $answer): RedirectResponse
    {
        $actor = $this->actor($request);

        $answer->decline($this->pending($actor, $membership), $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation declined.')]);

        return to_route('workspaces.index');
    }

    /**
     * Scoped to the actor, so another person's invitation is indistinguishable from a missing one.
     */
    private function pending(User $actor, string $id): WorkspaceMembership
    {
        return WorkspaceMembership::query()
            ->with('workspace')
            ->whereKey($id)
            ->where('user_id', $actor->id)
            ->where('status', WorkspaceMembershipStatus::Invited->value)
            ->firstOrFail();
    }

    /**
     * `redirect()->guest()` stores the intended URL, so login and registration return here.
     */
    private function towardsAnAccount(Request $request, string $address): RedirectResponse
    {
        if (User::query()->where('email', $address)->exists()) {
            $request->session()->flash('status', __('Sign in as :address to answer your invitation.', ['address' => $address]));

            return redirect()->guest(route('login'));
        }

        $request->session()->flash('invitation_email', $address);

        return redirect()->guest(route('register'));
    }
}
