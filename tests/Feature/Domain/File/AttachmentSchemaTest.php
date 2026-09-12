<?php

declare(strict_types=1);

use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $overrides
 */
function insertAttachment(string $fileId, Task $task, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    // Appends, so a second row collides on the constraint under test rather than on the position.
    $slot = (DB::table('attachments')->where('attachable_id', $task->id)->count() + 1) * SparsePosition::GAP;

    DB::table('attachments')->insert([
        'id' => $id,
        'file_id' => $fileId,
        'attachable_type' => 'task',
        'attachable_id' => $task->id,
        'position' => $slot,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);

    return $id;
}

it('points one file at one thing only once', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $file = insertFile($workspace);

    insertAttachment($file, $task);

    expect(fn (): string => DB::transaction(fn (): string => insertAttachment($file, $task)))
        ->toThrow(QueryException::class);
});

it('keeps one file in one slot', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();

    insertAttachment(insertFile($workspace), $task, ['position' => SparsePosition::GAP]);

    expect(fn (): string => DB::transaction(fn (): string => insertAttachment(
        insertFile($workspace),
        $task,
        ['position' => SparsePosition::GAP],
    )))->toThrow(QueryException::class);
});

it('lets one file hang from two things', function (): void {
    $workspace = Workspace::factory()->create();
    $file = insertFile($workspace);

    insertAttachment($file, Task::factory()->in($workspace)->create());
    insertAttachment($file, Task::factory()->in($workspace)->create());

    expect(DB::table('attachments')->where('file_id', $file)->count())->toBe(2);
});

it('goes when the file goes for good', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $file = insertFile($workspace);
    insertAttachment($file, $task);

    DB::table('files')->where('id', $file)->delete();

    expect(DB::table('attachments')->count())->toBe(0);
});

it('survives its file being hidden', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $file = insertFile($workspace);
    insertAttachment($file, $task);

    DB::table('files')->where('id', $file)->update(['deleted_at' => now()]);

    expect(DB::table('attachments')->count())->toBe(1);
});

it('refuses an attachment that names no file or no subject', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $file = insertFile($workspace);

    foreach (['file_id', 'attachable_type', 'attachable_id'] as $column) {
        expect(fn (): string => DB::transaction(fn (): string => insertAttachment($file, $task, [$column => null])))
            ->toThrow(QueryException::class);
    }
});

it('indexes the subject read and neither prefix of it', function (): void {
    $indexes = collect(Schema::getIndexes('attachments'))->pluck('columns');

    expect($indexes)->toContain(['attachable_type', 'attachable_id', 'created_at'])
        ->and($indexes)->not->toContain(['attachable_type', 'attachable_id'])
        ->and($indexes)->toContain(['file_id', 'attachable_type', 'attachable_id']);
});
