<?php

declare(strict_types=1);

use App\Domain\Comment\Actions\CreateComment;
use App\Domain\Comment\Data\CreateCommentData;
use App\Domain\Comment\Models\Comment;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Actions\FollowTask;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * A comment on a task the reader follows, so what the Inbox quotes is what the domain wrote.
 */
function commentFollowedBy(User $reader, Task $task, User $author, string $body): Comment
{
    app(FollowTask::class)->handle($task, $reader);

    return app(CreateComment::class)->handle($task, $author, new CreateCommentData(body: $body));
}

it('draws the person who caused a line the way every screen draws a person', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);

    assignTo($workspace, $actor, $reader);

    expect(inbox($workspace, $reader)['notifications'][0]['actor'])->toBe([
        'id' => $actor->id,
        'name' => $actor->name,
        'email' => $actor->email,
        'avatar' => null,
    ]);
});

it('names the projects a task lives in', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create(['name' => 'Launch', 'visibility' => ProjectVisibility::Workspace]);

    $task = assignTo($workspace, $actor, $reader);
    TaskProjectMembership::factory()->placing($task, $project)->create();

    expect(inbox($workspace, $reader)['notifications'][0]['subject']['projects'])->toBe([
        ['id' => $project->id, 'name' => 'Launch', 'color' => $project->color?->value],
    ]);
});

it('never names a project the reader cannot open', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Owner);
    $reader = memberOf($workspace);
    $open = Project::factory()->in($workspace)->create(['name' => 'Launch', 'visibility' => ProjectVisibility::Workspace]);
    $secret = Project::factory()->in($workspace)->create(['name' => 'Acquisition', 'visibility' => ProjectVisibility::Private]);

    $task = assignTo($workspace, $actor, $reader);
    TaskProjectMembership::factory()->placing($task, $open)->create();
    TaskProjectMembership::factory()->placing($task, $secret)->create();

    // The open project makes the task reachable, and that must not make the private one's name
    // readable through it.
    $projects = inbox($workspace, $reader)['notifications'][0]['subject']['projects'];

    expect(array_column($projects, 'name'))->toBe(['Launch']);
});

it('quotes what a comment said, with the people it names as names', function (): void {
    [$workspace, , $author] = placeableProject();
    $reader = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    commentFollowedBy($reader, $task, $author, "Over to  @[{$reader->name}](user:{$reader->id})\nfor the copy");

    expect(inbox($workspace, $reader)['notifications'][0]['excerpt'])
        ->toBe("Over to @{$reader->name} for the copy");
});

it('shortens a long comment rather than carrying all of it', function (): void {
    [$workspace, , $author] = placeableProject();
    $reader = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    commentFollowedBy($reader, $task, $author, str_repeat('word ', 100));

    expect(mb_strlen((string) inbox($workspace, $reader)['notifications'][0]['excerpt']))->toBeLessThanOrEqual(163);
});

it('quotes nothing for a line that is not a comment', function (): void {
    $workspace = Workspace::factory()->create();
    $reader = memberOf($workspace);

    assignTo($workspace, memberOf($workspace), $reader);

    expect(inbox($workspace, $reader)['notifications'][0]['excerpt'])->toBeNull();
});

it('keeps what was said from somebody who can no longer reach the task', function (): void {
    $workspace = Workspace::factory()->create();
    $author = memberOf($workspace, WorkspaceRole::Owner);
    $reader = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Workspace]);
    ProjectMembership::factory()->in($project)->forUser($author)->withAccess(ProjectAccessLevel::Owner)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    commentFollowedBy($reader, $task, $author, 'The acquisition price is agreed');

    $project->forceFill(['visibility' => ProjectVisibility::Private])->save();

    // The words belong to the task: losing the project is losing them too, even on a line
    // written while the reader could still open it.
    $row = inbox($workspace, $reader)['notifications'][0];

    expect($row['subject']['url'])->toBeNull()
        ->and($row['subject']['projects'])->toBe([])
        ->and($row['excerpt'])->toBeNull();
});

it('has nothing to quote once the comment is deleted', function (): void {
    [$workspace, , $author] = placeableProject();
    $reader = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    commentFollowedBy($reader, $task, $author, 'Never mind')->delete();

    $rows = inbox($workspace, $reader)['notifications'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['excerpt'])->toBeNull();
});

it('reads a page of comments from many people on many tasks in a fixed number of queries', function (): void {
    [$workspace, , $author] = placeableProject();
    $reader = memberOf($workspace);

    foreach (range(1, 10) as $index) {
        commentFollowedBy($reader, Task::factory()->in($workspace)->create(), $index % 2 === 0 ? $author : memberOf($workspace), "Comment {$index}");
    }

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $result = inbox($workspace, $reader);

    // What the assignment budget reads, plus the comments themselves — once for the page.
    expect($result['notifications'])->toHaveCount(10)
        ->and(array_filter(array_column($result['notifications'], 'excerpt')))->toHaveCount(10)
        ->and(count($queries))->toBeLessThanOrEqual(7);
});
