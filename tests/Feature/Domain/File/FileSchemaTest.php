<?php

declare(strict_types=1);

use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('records the disk beside the path', function (): void {
    // Stored per file so changing the attachments disk does not strand earlier uploads. See ADR-0007.
    expect(Schema::hasColumn('files', 'disk'))->toBeTrue()
        ->and(Schema::hasColumn('files', 'path'))->toBeTrue();
});

it('goes with the workspace', function (): void {
    $workspace = Workspace::factory()->create();
    insertFile($workspace, memberOf($workspace));

    $workspace->delete();

    expect(DB::table('files')->count())->toBe(0);
});

it('survives the account that uploaded it', function (): void {
    $workspace = Workspace::factory()->create();
    $uploader = memberOf($workspace);
    $id = insertFile($workspace, $uploader);

    $uploader->delete();

    $file = DB::table('files')->where('id', $id)->first();

    expect($file)->not->toBeNull()
        ->and($file?->uploaded_by)->toBeNull()
        ->and($file?->original_name)->toBe('quarterly plan.pdf');
});

it('refuses two rows pointing at one object', function (): void {
    $workspace = Workspace::factory()->create();
    $path = 'workspaces/'.$workspace->id.'/'.Str::uuid7();

    insertFile($workspace, null, ['path' => $path]);

    expect(fn (): string => DB::transaction(fn (): string => insertFile($workspace, null, ['path' => $path])))
        ->toThrow(QueryException::class);
});

it('lets the same path exist on a different disk', function (): void {
    $workspace = Workspace::factory()->create();
    $path = 'workspaces/'.$workspace->id.'/'.Str::uuid7();

    insertFile($workspace, null, ['path' => $path, 'disk' => 'attachments']);
    insertFile($workspace, null, ['path' => $path, 'disk' => 's3']);

    expect(DB::table('files')->where('path', $path)->count())->toBe(2);
});

it('refuses a file missing anything it needs to be found again', function (): void {
    $workspace = Workspace::factory()->create();

    foreach (['workspace_id', 'disk', 'path', 'original_name', 'mime_type', 'size', 'checksum', 'metadata'] as $column) {
        expect(fn (): string => DB::transaction(fn (): string => insertFile($workspace, null, [$column => null])))
            ->toThrow(QueryException::class);
    }
});

it('starts undeleted and can be hidden without losing the object', function (): void {
    $workspace = Workspace::factory()->create();
    $id = insertFile($workspace);

    expect(DB::table('files')->where('id', $id)->value('deleted_at'))->toBeNull();

    DB::table('files')->where('id', $id)->update(['deleted_at' => now()]);

    $file = DB::table('files')->where('id', $id)->first();

    expect($file?->deleted_at)->not->toBeNull()
        ->and($file?->path)->not->toBeNull();
});

it('indexes both cascades', function (): void {
    $indexes = collect(Schema::getIndexes('files'))->pluck('columns');

    expect($indexes)->toContain(['workspace_id'])
        ->and($indexes)->toContain(['uploaded_by'])
        ->and($indexes)->toContain(['disk', 'path']);
});
