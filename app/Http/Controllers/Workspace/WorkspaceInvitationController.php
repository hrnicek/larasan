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
    /**
     * The link in the invitation mail, which is signed and outside the auth group: following
     * it is how somebody with no account arrives at all. It answers nothing — it establishes
     * who is holding the link and sends them to the list where the invitation waits.
     */
    public function show(Request $request, string $membership, ClaimWorkspaceInvitations $claim): RedirectResponse
    {
        $invitation = WorkspaceMembership::query()->whereKey($membership)->firstOrFail();
        $address = $invitation->address();
        $actor = $request->user();

        if (! $actor instanceof User) {
            return $this->towardsAnAccount($request, $address);
        }

        if (mb_strtolower($actor->email) === mb_strtolower($address)) {
            /*
             * The row may still be addressed to nobody: an account invited before it existed
             * is claimed on `Registered`, and one that arrived here some other way — an
             * address changed after the invitation was sent — is claimed now.
             */
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

        // They land in the workspace they have just joined rather than in whichever one the
        // stored choice still names.
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
     * Scoped to the actor's own rows, so an invitation addressed to somebody else is a 404
     * rather than a permission error — the same answer an id that does not exist gets. The
     * Action refuses it as well; this is what keeps the refusal from naming a stranger's
     * invitation.
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
     * A guest holding an invitation link needs an account before they can answer. Which of
     * the two screens depends on whether the address already has one — and `guest()` records
     * the invitation as the intended destination, so both Fortify responses come back here.
     */
    private function towardsAnAccount(Request $request, string $address): RedirectResponse
    {
        if (User::query()->where('email', $address)->exists()) {
            $request->session()->flash('status', __('Sign in as :address to answer your invitation.', ['address' => $address]));

            return redirect()->guest(route('login'));
        }

        // Prefilled rather than merely suggested: an invitation is only claimed by the address
        // it was sent to, and registering under another one silently loses it.
        $request->session()->flash('invitation_email', $address);

        return redirect()->guest(route('register'));
    }
}
