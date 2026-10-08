<?php

declare(strict_types=1);

use App\Domain\File\Models\Attachment;
use App\Domain\File\Models\File;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;

it('hangs from its workspace and the account that uploaded it', function (): void {
    $workspace = Workspace::factory()->create();
    $uploader = memberOf($workspace);

    $file = File::factory()->in($workspace)->by($uploader)->create();

    expect($file->workspace->is($workspace))->toBeTrue()
        ->and($file->uploader?->is($uploader))->toBeTrue()
        ->and($file->disk)->toBe(config('filesystems.attachments'));
});

it('refuses to have its location or its size mass assigned', function (): void {
    foreach (['disk', 'path', 'size', 'checksum', 'workspace_id', 'uploaded_by'] as $attribute) {
        expect(fn (): File => (new File)->fill([$attribute => 'anything']))
            ->toThrow(MassAssignmentException::class);
    }
});

it("reads a task's attachments in position order", function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();

    foreach (['Third' => 3000, 'First' => 1000, 'Second' => 2000] as $name => $position) {
        $file = File::factory()->in($workspace)->create(['original_name' => $name]);
        Attachment::factory()->attaching($file, $task)->create(['position' => $position]);
    }

    expect($task->attachments()->with('file')->get()->map(fn (Attachment $a): string => $a->file->original_name)->all())
        ->toBe(['First', 'Second', 'Third']);
});

it("keeps another subject's attachments out", function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $other = Task::factory()->in($workspace)->create();

    Attachment::factory()->attaching(File::factory()->in($workspace)->create(), $task)->create();
    Attachment::factory()->attaching(File::factory()->in($workspace)->create(), $other)->create();

    expect($task->attachments()->count())->toBe(1);
});

it('writes a short name into the type column, never a class name', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $attachment = Attachment::factory()->attaching(File::factory()->in($workspace)->create(), $task)->create();

    expect(DB::table('attachments')->where('id', $attachment->id)->value('attachable_type'))->toBe('task');
});

it('refuses to have its file or its subject mass assigned', function (): void {
    expect(fn (): Attachment => (new Attachment)->fill(['file_id' => 'anything']))
        ->toThrow(MassAssignmentException::class);
});

it('hides a soft-deleted file without losing the object behind it', function (): void {
    $workspace = Workspace::factory()->create();
    $file = File::factory()->in($workspace)->create();
    $path = $file->path;

    $file->delete();

    expect(File::query()->count())->toBe(0)
        ->and(DB::table('files')->where('id', $file->id)->value('path'))->toBe($path);
});

it('gives a factory file an uploader who is actually in the workspace', function (): void {
    $file = File::factory()->create();

    expect($file->workspace->hasActiveMember((int) $file->uploaded_by))->toBeTrue();
});

it('generates a path rather than deriving one from the name', function (): void {
    $workspace = Workspace::factory()->create();

    $file = File::factory()->in($workspace)->create(['original_name' => 'quarterly plan.pdf']);

    expect($file->path)->toStartWith("workspaces/{$workspace->id}/")
        ->and($file->path)->not->toContain('quarterly');
});
