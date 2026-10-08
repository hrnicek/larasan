<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Actions\AnswerWorkspaceInvitation;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Domain\Workspace\Notifications\WorkspaceMemberJoined;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * @return array{Workspace, WorkspaceMembership, User, User}
 */
function invitationToJoin(): array
{
    $workspace = Workspace::factory()->create(['name' => 'Northwind']);
    $inviter = memberOf($workspace, WorkspaceRole::Admin, user: User::factory()->create(['name' => 'Ada Admin']));
    $invitee = User::factory()->create(['name' => 'Nora New', 'email' => 'nora@example.com']);

    $membership = WorkspaceMembership::factory()->invited($inviter)->create([
        'workspace_id' => $workspace->id,
        'user_id' => $invitee->id,
        'role' => WorkspaceRole::Member,
    ]);

    return [$workspace, $membership, $invitee, $inviter];
}

it('mails every active owner of the workspace once someone joins', function (): void {
    Notification::fake();
    [$workspace, $membership, $invitee] = invitationToJoin();
    $owner = memberOf($workspace, WorkspaceRole::Owner);
    $coOwner = memberOf($workspace, WorkspaceRole::Owner);

    app(AnswerWorkspaceInvitation::class)->accept($membership, $invitee);

    foreach ([$owner, $coOwner] as $recipient) {
        Notification::assertSentTo(
            $recipient,
            WorkspaceMemberJoined::class,
            fn (WorkspaceMemberJoined $notification, array $channels): bool => $channels === ['mail']
                && $notification->queue === 'notifications',
        );
    }

    Notification::assertSentTimes(WorkspaceMemberJoined::class, 2);
});

it('tells nobody but the owners of that workspace', function (): void {
    Notification::fake();
    [$workspace, $membership, $invitee, $inviter] = invitationToJoin();
    memberOf($workspace, WorkspaceRole::Owner);
    $revokedOwner = memberOf($workspace, WorkspaceRole::Owner, WorkspaceMembershipStatus::Revoked);
    $member = memberOf($workspace, WorkspaceRole::Member);
    $elsewhereOwner = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    app(AnswerWorkspaceInvitation::class)->accept($membership, $invitee);

    Notification::assertNotSentTo([$inviter, $revokedOwner, $member, $elsewhereOwner, $invitee], WorkspaceMemberJoined::class);
    Notification::assertSentTimes(WorkspaceMemberJoined::class, 1);
});

it('mails nobody when the invitation is declined', function (): void {
    Notification::fake();
    [$workspace, $membership, $invitee] = invitationToJoin();
    memberOf($workspace, WorkspaceRole::Owner);

    app(AnswerWorkspaceInvitation::class)->decline($membership, $invitee);

    Notification::assertNothingSent();
});

it('names who joined, where, in which role and on whose invitation', function (): void {
    [$workspace, $membership, $invitee] = invitationToJoin();
    $owner = memberOf($workspace, WorkspaceRole::Owner);
    app(AnswerWorkspaceInvitation::class)->accept($membership, $invitee);

    $mail = (new WorkspaceMemberJoined($membership->id))->toMail($owner);
    $body = implode(' ', $mail->introLines);

    expect($mail->subject)->toBe('Nora New joined Northwind')
        ->and($body)->toContain('Nora New (nora@example.com)')
        ->toContain('Northwind')
        ->toContain('member')
        ->toContain('Ada Admin')
        ->and($mail->actionUrl)->toBe(route('workspaces.members'));
});
