<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Notifications;

use App\Domain\Workspace\Models\WorkspaceMembership;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceMemberJoined extends Notification implements ShouldQueue
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
        $membership = WorkspaceMembership::query()->with('workspace', 'user', 'invitedBy')->findOrFail($this->membershipId);
        $name = $membership->user->name ?? $membership->address();

        return (new MailMessage)
            ->subject(__(':name joined :workspace', [
                'name' => $name,
                'workspace' => $membership->workspace->name,
            ]))
            ->line(__(':name (:email) accepted the invitation from :inviter and joined :workspace as a :role.', [
                'name' => $name,
                'email' => $membership->address(),
                'inviter' => $membership->invitedBy->name ?? __('someone'),
                'workspace' => $membership->workspace->name,
                'role' => $membership->role->value,
            ]))
            ->action(__('View members'), route('workspaces.members'));
    }
}
