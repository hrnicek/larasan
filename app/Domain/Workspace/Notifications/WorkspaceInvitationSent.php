<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Notifications;

use App\Domain\Workspace\Models\WorkspaceMembership;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
            ->action(__('View the invitation'), route('workspaces.index'));
    }
}
