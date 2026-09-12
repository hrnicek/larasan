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
                'inviter' => $membership->invitedBy->name ?? __('Someone'),
                'workspace' => $membership->workspace->name,
                'role' => $membership->role->value,
            ]))
            ->action(__('View the invitation'), $this->link($membership));
    }

    /**
     * Signed rather than behind auth, because the invited address may not have an account yet.
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
