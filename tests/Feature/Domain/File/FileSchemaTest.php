<?php

declare(strict_types=1);

use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Asserted through raw inserts, before a model exists, so what is proven is the database's
 * behaviour rather than a model's.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertFile(Workspace $workspace, ?User $uploader = null, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('files')->insert([
        'id' => $id,
        'workspace_id' => $workspace->id,
        'uploaded_by' => $uploader?->id,
        'disk' => 'attachments',
        'path' => "workspaces/{$workspace->id}/".Str::uuid7(),
        'original_name' => 'quarterly plan.pdf',
        'mime_type' => 'application/pdf',
        'extension' => 'pdf',
        'size' => 42_000,
        'checksum' => str_repeat('a', 64),
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);

    return $id;
}

it('records the disk beside the path', function (): void {
    /*
     * The disk a file was written to is a fact about that file rather than about today's
     * configuration (ADR-0007), so changing `FILESYSTEM_ATTACHMENTS_DISK` moves new uploads
     * without stranding old ones.
     */
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

    // A file other people are still working with must not disappear because the person who
    // uploaded it left.
    $file = DB::table('files')->where('id', $id)->first();

    expect($file)->not->toBeNull()
        ->and($file?->uploaded_by)->toBeNull()
        ->and($file?->original_name)->toBe('quarterly plan.pdf');
});

it('refuses two rows pointing at one object', function (): void {
    $workspace = Workspace::factory()->create();
    $path = 'workspaces/'.$workspace->id.'/'.Str::uuid7();

    insertFile($workspace, null, ['path' => $path]);

    /*
     * Two rows on one path would make deleting either of them delete the other's bytes — the
     * kind of bug that is only ever found by losing somebody's file.
     */
    expect(fn (): string => DB::transaction(fn (): string => insertFile($workspace, null, ['path' => $path])))
        ->toThrow(QueryException::class);
});

it('lets the same path exist on a different disk', function (): void {
    $workspace = Workspace::factory()->create();
    $path = 'workspaces/'.$workspace->id.'/'.Str::uuid7();

    insertFile($workspace, null, ['path' => $path, 'disk' => 'attachments']);
    insertFile($workspace, null, ['path' => $path, 'disk' => 's3']);

    // The pair identifies an object; the path alone does not.
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

    // The row stops being reachable before the bytes are removed: deleting bytes inside a
    // request is the one part of this that cannot be undone (TASK-120-007).
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
