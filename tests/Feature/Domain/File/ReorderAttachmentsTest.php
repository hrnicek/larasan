<?php

declare(strict_types=1);

use App\Domain\File\Actions\MoveAttachment;
use App\Domain\File\Exceptions\FileException;
use App\Domain\File\Models\Attachment;
use App\Domain\File\Models\File;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake(config('filesystems.attachments'));
});

/**
 * @return list<Attachment>
 */
function attachmentsOn(Task $task, int $count): array
{
    return array_map(
        fn (): Attachment => Attachment::factory()
            ->attaching(File::factory()->in($task->workspace)->create(), $task)
            ->create(),
        range(1, $count),
    );
}

/**
 * @return list<string>
 */
function orderOn(Task $task): array
{
    return array_values(array_map(
        static fn (Attachment $attachment): string => $attachment->id,
        $task->attachments()->get()->all(),
    ));
}

function moveAttachment(Attachment $attachment, User $actor, ?Attachment $after): Attachment
{
    return app(MoveAttachment::class)->handle($attachment, $actor, $after);
}

it('appends an upload to the end of what is already there', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    foreach (['one.pdf', 'two.pdf'] as $name) {
        $this->actingAs($actor)->post(route('tasks.attachments.store', $task), [
            'files' => [UploadedFile::fake()->create($name, 12, 'application/pdf')],
        ]);
    }

    $positions = $task->attachments()->pluck('position')->all();

    expect($positions)->toBe([SparsePosition::GAP, SparsePosition::GAP * 2]);
});

it('moves a file behind another one', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    [$first, $second, $third] = attachmentsOn($task, 3);

    moveAttachment($third, $actor, $first);

    expect(orderOn($task))->toBe([$first->id, $third->id, $second->id]);
});

it('moves a file to the front when it follows nothing', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    [$first, $second, $third] = attachmentsOn($task, 3);

    moveAttachment($third, $actor, null);

    expect(orderOn($task))->toBe([$third->id, $first->id, $second->id]);
});

it('writes nothing when the file is already in that slot', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    [$first, $second] = attachmentsOn($task, 2);

    $before = $second->position;

    moveAttachment($second, $actor, $first);

    expect($second->refresh()->position)->toBe($before);
});

it('respreads the list when the neighbours have closed up', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    [$first, $second, $third] = attachmentsOn($task, 3);

    // Too close for a midpoint, so the move must normalise. See ADR-0009.
    $first->forceFill(['position' => 10])->save();
    $second->forceFill(['position' => 12])->save();

    moveAttachment($third, $actor, $first);

    expect(orderOn($task))->toBe([$first->id, $third->id, $second->id])
        ->and($task->attachments()->pluck('position')->all())->each->toBeGreaterThan(0);
});

it('refuses an anchor attached to something else', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $other = Task::factory()->in($workspace)->create();
    [$attachment] = attachmentsOn($task, 1);
    [$elsewhere] = attachmentsOn($other, 1);

    expect(fn (): Attachment => moveAttachment($attachment, $actor, $elsewhere))
        ->toThrow(FileException::class);
});

it('refuses to place a file after itself', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    [$attachment] = attachmentsOn($task, 1);

    expect(fn (): Attachment => moveAttachment($attachment, $actor, $attachment))
        ->toThrow(FileException::class);
});

it('refuses a reader who may open the task but not change it', function (): void {
    $workspace = Workspace::factory()->create();
    $viewer = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($project)->forUser($viewer)->withAccess(ProjectAccessLevel::Viewer)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();
    [$first, $second] = attachmentsOn($task, 2);

    expect(fn (): Attachment => moveAttachment($second, $viewer, $first))->toThrow(FileException::class);

    $this->actingAs($viewer)
        ->from(route('tasks.show', $task))
        ->put(route('attachments.move', $second), ['after' => $first->id])
        ->assertForbidden();
});

it('moves a file through the endpoint', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    [$first, $second, $third] = attachmentsOn($task, 3);

    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->put(route('attachments.move', $third), ['after' => $first->id])
        ->assertRedirect(route('tasks.show', $task));

    expect(orderOn($task))->toBe([$first->id, $third->id, $second->id]);
});

it('rejects an anchor from another task at the door', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $other = Task::factory()->in($workspace)->create();
    [$attachment] = attachmentsOn($task, 1);
    [$elsewhere] = attachmentsOn($other, 1);

    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->put(route('attachments.move', $attachment), ['after' => $elsewhere->id])
        ->assertSessionHasErrors('after');
});

it('hides a move on an attachment in another workspace behind a 404', function (): void {
    [$workspace] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    [$attachment] = attachmentsOn($task, 1);
    $stranger = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    $this->actingAs($stranger)
        ->put(route('attachments.move', $attachment), ['after' => null])
        ->assertNotFound();
});

it('moves the file when its slot after normalising is the number it held before', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    [$first, $second, $third] = attachmentsOn($task, 3);

    $first->forceFill(['position' => 10])->save();
    $second->forceFill(['position' => 12])->save();
    $third->forceFill(['position' => 98304])->save();

    moveAttachment($third, $actor, $first);

    expect(orderOn($task))->toBe([$first->id, $third->id, $second->id]);
});
