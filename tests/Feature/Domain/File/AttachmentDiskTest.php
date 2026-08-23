<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 * The Phase 120 review found attachments sitting on a disk the framework serves and moved them
 * to one it does not. Nothing pinned that afterwards, so this does (TASK-180-006): the disk's
 * `serve` flag, where its bytes live relative to the served disk, and that the served route
 * cannot be talked into reaching them.
 */

it('keeps attachments on a disk the framework does not serve', function (): void {
    $disk = config('filesystems.attachments');

    expect(config("filesystems.disks.{$disk}.serve"))->toBeFalse();
})->with([
    'a served disk registers GET /storage/{path}, and a storage path is never a capability
    (ADR-0007)',
]);

it('stores attachments outside the root the served disk exposes', function (): void {
    $attachments = (string) config('filesystems.disks.'.config('filesystems.attachments').'.root');
    $served = (string) config('filesystems.disks.local.root');

    expect(str_starts_with($attachments, rtrim($served, '/').'/'))->toBeFalse()
        ->and($attachments)->not->toBe($served);
})->with([
    'the flag is one guard and the layout is the other — a disk that served the same directory
    would expose them whatever its own flag said',
]);

it('refuses to serve an attachment through the served disks route', function (): void {
    $relative = 'a/b/attachment.pdf';

    /*
     * Unsigned, and then aimed above the served root. Both are refused by the signature check
     * before the path is ever looked at, which is the order that matters: the answer says
     * nothing about whether the file is there.
     */
    $this->get("/storage/{$relative}")->assertForbidden();
    $this->get('/storage/../attachments/'.$relative)->assertForbidden();
});

it('never asks the disk for a URL', function (): void {
    $offenders = collect(File::allFiles(app_path()))
        ->merge(File::allFiles(resource_path('js')))
        ->filter(fn (SplFileInfo $file): bool => str_contains(codeWithoutComments($file->getPathname()), 'Storage::url(')
            || str_contains(codeWithoutComments($file->getPathname()), 'temporaryUrl(')
            || (bool) preg_match('/Storage::disk\([^)]*\)->url\(/', codeWithoutComments($file->getPathname())))
        ->map(fn (SplFileInfo $file): string => $file->getFilename())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
})->with([
    'a URL from the disk is a capability handed out without asking the question the download
    endpoint asks',
]);

/**
 * A file's code with its comments removed.
 *
 * `AttachmentController` explains in a docblock why it does not call `Storage::url()`, and a
 * check that reads comments would call that a violation — which is how a rule ends up being
 * deleted for being wrong rather than fixed.
 */
function codeWithoutComments(string $path): string
{
    $contents = (string) file_get_contents($path);

    if (! str_ends_with($path, '.php')) {
        return $contents;
    }

    return implode('', array_map(
        static fn (array|string $token): string => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
            ? ''
            : (is_array($token) ? $token[1] : $token),
        token_get_all($contents),
    ));
}
