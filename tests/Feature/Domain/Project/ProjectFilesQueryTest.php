<?php

declare(strict_types=1);

use App\Domain\File\Models\Attachment;
use App\Domain\File\Models\File;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Project\Queries\ProjectFilesQuery;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Actions\DeleteTask;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/**
 * @return array<string, mixed>
 */
function filesOf(Project $project, User $actor, int $page = 1, int $perPage = ProjectFilesQuery::PER_PAGE): array
{
    return app(ProjectFilesQuery::class)($project, $actor, $page, $perPage);
}

/**
 * A file hanging off a task, written the way the Action writes it: the row, not an upload.
 *
 * @param  array<string, mixed>  $attributes
 */
function hanging(Workspace $workspace, Task $task, ?User $uploader = null, array $attributes = []): Attachment
{
    $file = File::factory()->in($workspace);

    if ($uploader instanceof User) {
        $file = $file->by($uploader);
    }

    return Attachment::factory()->attaching($file->create($attributes), $task)->create();
}

/**
 * The names the table drew, in the order it drew them.
 *
 * @param  array<string, mixed>  $files
 * @return list<string>
 */
function drawn(array $files): array
{
    /** @var list<array<string, mixed>> $rows */
    $rows = $files['files'];

    return array_map(fn (array $row): string => (string) $row['name'], $rows);
}

it('lists what hangs off the tasks this project holds', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create(['title' => 'Write the brief']);
    attach($task, $project, $actor);

    hanging($workspace, $task, $actor, ['original_name' => 'brief.pdf']);

    $files = filesOf($project, $actor);

    /** @var list<array<string, mixed>> $rows */
    $rows = $files['files'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('brief.pdf')
        ->and($rows[0]['task'])->toBe(['id' => $task->id, 'title' => 'Write the brief'])
        ->and($rows[0]['uploader']['id'])->toBe($actor->id)
        ->and($rows[0]['kind'])->toBe('pdf')
        ->and($rows[0]['attachedAt'])->not->toBeNull();
});

it('leaves out a file hanging off a task in another project', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $mine = Task::factory()->in($workspace)->create();
    attach($mine, $project, $actor);

    $elsewhere = Project::factory()->in($workspace)->create();
    $theirs = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($theirs, $elsewhere)->create();

    hanging($workspace, $mine, $actor, ['original_name' => 'mine.pdf']);
    hanging($workspace, $theirs, $actor, ['original_name' => 'theirs.pdf']);

    expect(drawn(filesOf($project, $actor)))->toBe(['mine.pdf']);
});

it('leaves out a file whose file belongs to another workspace', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    attach($task, $project, $actor);

    hanging($workspace, $task, $actor, ['original_name' => 'ours.pdf']);

    // A row the domain would never write, which is exactly why the scope is proven in the query
    // rather than left to the Action that usually creates it.
    $elsewhere = Workspace::factory()->create();
    hanging($elsewhere, $task, null, ['original_name' => 'somebody elses.pdf']);

    expect(drawn(filesOf($project, $actor)))->toBe(['ours.pdf']);
});

it('stops drawing a file once its task is deleted', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    attach($task, $project, $actor);
    hanging($workspace, $task, $actor);

    app(DeleteTask::class)->handle($task, $actor);

    expect(filesOf($project, $actor)['files'])->toBe([]);
});

it('stops drawing a file once the file is removed', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    attach($task, $project, $actor);
    $attachment = hanging($workspace, $task, $actor);

    $attachment->file->delete();

    expect(filesOf($project, $actor)['files'])->toBe([]);
});

it('draws one row per attachment when one file hangs from two tasks', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $first = Task::factory()->in($workspace)->create(['title' => 'First']);
    $second = Task::factory()->in($workspace)->create(['title' => 'Second']);
    attach($first, $project, $actor);
    attach($second, $project, $actor);

    $file = File::factory()->in($workspace)->by($actor)->create(['original_name' => 'shared.pdf']);
    Attachment::factory()->attaching($file, $first)->create();
    Attachment::factory()->attaching($file, $second)->create();

    /** @var list<array<string, mixed>> $rows */
    $rows = filesOf($project, $actor)['files'];

    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->pluck('task.title')->sort()->values()->all())->toBe(['First', 'Second']);
});

it('reads the kind from the type the upload was sniffed as', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    attach($task, $project, $actor);

    // A PNG that calls itself a spreadsheet. The MIME type is what the file is (ADR-0007).
    hanging($workspace, $task, $actor, [
        'original_name' => 'chart.xlsx',
        'mime_type' => 'image/png',
        'extension' => 'xlsx',
    ]);

    /** @var list<array<string, mixed>> $rows */
    $rows = filesOf($project, $actor)['files'];

    expect($rows[0]['kind'])->toBe('image');
});

it('says nothing about an uploader who has left rather than inventing one', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    attach($task, $project, $actor);

    $uploader = memberOf($workspace);
    hanging($workspace, $task, $uploader);

    $uploader->delete();

    /** @var list<array<string, mixed>> $rows */
    $rows = filesOf($project, $actor)['files'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['uploader'])->toBeNull();
});

it('bounds the page and says how much it did not draw', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    attach($task, $project, $actor);

    foreach (range(1, 5) as $index) {
        hanging($workspace, $task, $actor, ['original_name' => "file {$index}.pdf"]);
    }

    $first = filesOf($project, $actor, page: 1, perPage: 2);
    $last = filesOf($project, $actor, page: 3, perPage: 2);

    expect($first['files'])->toHaveCount(2)
        ->and($first['meta'])->toBe(['page' => 1, 'perPage' => 2, 'total' => 5, 'hasMore' => true])
        ->and($last['files'])->toHaveCount(1)
        ->and($last['meta']['hasMore'])->toBeFalse();
});

it('lets a guest remove what they uploaded and nothing else', function (): void {
    [$workspace, $project] = placeableProject();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Commenter)->create();

    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    hanging($workspace, $task, $guest, ['original_name' => 'theirs.pdf']);
    hanging($workspace, $task, memberOf($workspace), ['original_name' => 'somebody elses.pdf']);

    /** @var list<array<string, mixed>> $rows */
    $rows = filesOf($project, $guest)['files'];

    expect(collect($rows)->pluck('canDelete', 'name')->all())
        ->toBe(['somebody elses.pdf' => false, 'theirs.pdf' => true]);
});

it('lets a member remove anything, because the workspace moderates its files', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    attach($task, $project, $actor);

    hanging($workspace, $task, memberOf($workspace), ['original_name' => 'somebody elses.pdf']);

    /** @var list<array<string, mixed>> $rows */
    $rows = filesOf($project, $actor)['files'];

    expect($rows[0]['canDelete'])->toBeTrue();
});
