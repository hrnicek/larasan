<?php

declare(strict_types=1);

use App\Domain\Comment\Actions\CreateComment;
use App\Domain\Comment\Actions\UpdateComment;
use App\Domain\Comment\Data\CreateCommentData;
use App\Domain\Comment\Data\UpdateCommentData;
use App\Domain\Comment\Events\CommentCreated;
use App\Domain\Comment\Events\CommentEdited;
use App\Domain\Comment\Exceptions\CommentException;
use App\Domain\Comment\Models\Comment;
use App\Domain\Comment\Support\Mentions;
use App\Domain\Notification\Notifications\MentionedInCommentNotification;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function writeMentioning(Task $task, User $author, string $body): Comment
{
    return app(CreateComment::class)->handle($task, $author, new CreateCommentData(body: $body));
}

const CANNOT_MENTION = 'Only people who can see this task can be mentioned on it.';

it('stores the id with the name the person has now, whatever name was sent', function (): void {
    [$workspace, , $author] = placeableProject();
    $jana = memberOf($workspace, user: User::factory()->create(['name' => 'Jana Nováková']));
    $task = Task::factory()->in($workspace)->create();

    $comment = writeMentioning($task, $author, 'Can you look, '.mentionOf($jana, 'Anybody').'?');

    expect($comment->body)->toBe('Can you look, @[Jana Nováková](user:'.$jana->id.')?');
});

it('announces who was named, once each', function (): void {
    [$workspace, , $author] = placeableProject();
    $jana = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    Event::fake();
    writeMentioning($task, $author, mentionOf($jana).' and again '.mentionOf($jana));

    Event::assertDispatched(CommentCreated::class, fn (CommentCreated $event): bool => $event->mentionedIds === [$jana->id]);
});

it('lets the author name themselves', function (): void {
    [$workspace, , $author] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    expect(writeMentioning($task, $author, 'Note to '.mentionOf($author))->exists)->toBeTrue();
});

it('refuses a mention of somebody in another workspace, and names nobody while refusing', function (): void {
    [$workspace, , $author] = placeableProject();
    $stranger = memberOf(Workspace::factory()->create(), user: User::factory()->create(['name' => 'Stranger Name']));
    $task = Task::factory()->in($workspace)->create();

    expect(fn (): Comment => writeMentioning($task, $author, 'Hi '.mentionOf($stranger)))
        ->toThrow(CommentException::class, CANNOT_MENTION);

    expect($task->comments()->count())->toBe(0);
});

it('refuses an id that names no account at all', function (): void {
    [$workspace, , $author] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    expect(fn (): Comment => writeMentioning($task, $author, 'Hi @[Ghost](user:987654321)'))
        ->toThrow(CommentException::class, CANNOT_MENTION);
});

it('refuses somebody whose membership here has ended', function (): void {
    [$workspace, , $author] = placeableProject();
    $former = memberOf($workspace, status: WorkspaceMembershipStatus::Revoked);
    $task = Task::factory()->in($workspace)->create();

    expect(fn (): Comment => writeMentioning($task, $author, 'Hi '.mentionOf($former)))
        ->toThrow(CommentException::class, CANNOT_MENTION);
});

it('refuses a member who cannot read the task', function (): void {
    $workspace = Workspace::factory()->create();
    $author = memberOf($workspace, WorkspaceRole::Owner);
    $colleague = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($project)->forUser($author)->withAccess(ProjectAccessLevel::Owner)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    // Naming somebody in a project they were never given would tell them it exists.
    expect(fn (): Comment => writeMentioning($task, $author, 'Hi '.mentionOf($colleague)))
        ->toThrow(CommentException::class, CANNOT_MENTION);

    expect($task->comments()->count())->toBe(0);
});

it('takes as many names as the limit and refuses one more', function (): void {
    [$workspace, , $author] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $people = collect(range(1, Mentions::LIMIT + 1))->map(fn (): User => memberOf($workspace));

    $body = fn (int $count): string => $people->take($count)->map(fn (User $person): string => mentionOf($person))->implode(' ');

    expect(writeMentioning($task, $author, $body(Mentions::LIMIT))->exists)->toBeTrue();

    expect(fn (): Comment => writeMentioning($task, $author, $body(Mentions::LIMIT + 1)))
        ->toThrow(CommentException::class, 'A comment can mention at most 20 people.');
});

it('rewrites the names on an edit and announces everybody the edit names', function (): void {
    [$workspace, , $author] = placeableProject();
    $jana = memberOf($workspace, user: User::factory()->create(['name' => 'Jana Nováková']));
    $task = Task::factory()->in($workspace)->create();
    $comment = writeMentioning($task, $author, 'First thought');

    Event::fake();
    app(UpdateComment::class)->handle($comment, $author, new UpdateCommentData(body: 'Now '.mentionOf($jana, 'J')));

    expect($comment->refresh()->body)->toBe('Now @[Jana Nováková](user:'.$jana->id.')');

    Event::assertDispatched(CommentEdited::class, fn (CommentEdited $event): bool => $event->mentionedIds === [$jana->id]);
});

it('refuses an edit that names somebody who cannot read the task', function (): void {
    [$workspace, , $author] = placeableProject();
    $stranger = memberOf(Workspace::factory()->create());
    $task = Task::factory()->in($workspace)->create();
    $comment = writeMentioning($task, $author, 'First thought');

    expect(fn (): Comment => app(UpdateComment::class)->handle($comment, $author, new UpdateCommentData(body: mentionOf($stranger))))
        ->toThrow(CommentException::class, CANNOT_MENTION);

    expect($comment->refresh()->body)->toBe('First thought');
});

it('does not mark an edit when only a stale name was sent back', function (): void {
    [$workspace, , $author] = placeableProject();
    $jana = memberOf($workspace, user: User::factory()->create(['name' => 'Jana Nováková']));
    $task = Task::factory()->in($workspace)->create();
    $comment = writeMentioning($task, $author, 'Ask '.mentionOf($jana));

    app(UpdateComment::class)->handle($comment, $author, new UpdateCommentData(body: 'Ask '.mentionOf($jana, 'Jana N.')));

    expect($comment->refresh()->isEdited())->toBeFalse();
});

it('writes a mention over HTTP and the person named hears about it', function (): void {
    [$workspace, , $author] = placeableProject();
    $jana = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($author)
        ->from(route('tasks.show', $task))
        ->post(route('tasks.comments.store', $task), ['body' => 'Over to '.mentionOf($jana)])
        ->assertRedirect(route('tasks.show', $task))
        ->assertSessionHasNoErrors();

    expect(DB::table('notifications')->where('notifiable_id', $jana->id)->value('type'))
        ->toBe(MentionedInCommentNotification::class);
});

it('refuses a mention of a stranger over HTTP without writing the comment', function (): void {
    [$workspace, , $author] = placeableProject();
    $stranger = memberOf(Workspace::factory()->create());
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($author)
        ->from(route('tasks.show', $task))
        ->post(route('tasks.comments.store', $task), ['body' => 'Hi '.mentionOf($stranger)])
        ->assertRedirect(route('tasks.show', $task))
        ->assertSessionHasErrors(['refusal' => CANNOT_MENTION]);

    expect($task->comments()->count())->toBe(0)
        ->and(DB::table('notifications')->count())->toBe(0);
});
