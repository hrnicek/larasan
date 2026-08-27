<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Notifications;

use App\Domain\Workspace\Models\WorkspaceMembership;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Queued on the `notifications` queue Horizon already supervises (ADR-0008). The
 * invitation exists whether or not the mail goes out, so sending it inline would let a
 * mail failure roll back a decision the inviter already made.
 */
class WorkspaceInvitationSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $membershipId)
    {
        $this->onQueue('notifications');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $membership = WorkspaceMembership::query()->with('workspace', 'invitedBy')->findOrFail($this->membershipId);

        return (new MailMessage)
            ->subject(__('You have been invited to :workspace', ['workspace' => $membership->workspace->name]))
            ->line(__(':inviter invited you to join :workspace as a :role.', [
                // The column is nullable, and so is the row it points at: the inviter's account
                // may have been deleted between the invitation and this mail being built.
                'inviter' => $membership->invitedBy->name ?? __('Someone'),
                'workspace' => $membership->workspace->name,
                'role' => $membership->role->value,
            ]))
            ->action(__('View the invitation'), $this->link($membership));
    }

    /**
     * Signed and expiring with the invitation itself, so the link is neither guessable nor
     * useful once the deadline it was sent with has passed. It is outside the auth group,
     * because the address it was sent to may have no account yet — the link is how they
     * arrive at one.
     */
    private function link(WorkspaceMembership $membership): string
    {
        return URL::temporarySignedRoute(
            'workspaces.invitations.show',
            $membership->expires_at ?? CarbonImmutable::now()->addWeek(),
            ['membership' => $membership->id],
        );
    }
}
