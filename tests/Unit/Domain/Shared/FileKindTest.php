<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\FileKind;

it('reads the kind from the type the upload was sniffed as', function (string $mime, FileKind $kind): void {
    expect(FileKind::fromMime($mime))->toBe($kind);
})->with([
    'a photograph' => ['image/png', FileKind::Image],
    'a recording' => ['video/quicktime', FileKind::Video],
    'a voice note' => ['audio/mpeg', FileKind::Audio],
    'a pdf' => ['application/pdf', FileKind::Pdf],
    'a word document' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', FileKind::Document],
    'an older word document' => ['application/msword', FileKind::Document],
    'a spreadsheet' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', FileKind::Spreadsheet],
    'a deck' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', FileKind::Presentation],
    'an open document' => ['application/vnd.oasis.opendocument.text', FileKind::Document],
    'an archive' => ['application/zip', FileKind::Archive],
    'plain text' => ['text/plain', FileKind::Text],
    'something nobody recognised' => ['application/octet-stream', FileKind::Other],
]);

it('ignores the extension while the type is still saying something', function (): void {
    // The extension is only the uploader's claim, so a meaningful MIME type wins. See ADR-0007.
    expect(FileKind::fromMime('image/png', 'xlsx'))->toBe(FileKind::Image);
});

it('falls back to the extension only where the type says nothing useful', function (string $extension, FileKind $kind): void {
    expect(FileKind::fromMime('application/octet-stream', $extension))->toBe($kind);
})->with([
    'a document' => ['docx', FileKind::Document],
    'a spreadsheet' => ['csv', FileKind::Spreadsheet],
    'a deck' => ['key', FileKind::Presentation],
    'an archive' => ['7z', FileKind::Archive],
    'a pdf' => ['pdf', FileKind::Pdf],
    'notes' => ['md', FileKind::Text],
    'nothing at all' => ['', FileKind::Other],
]);

it('reads a type in any case', function (): void {
    expect(FileKind::fromMime('IMAGE/PNG'))->toBe(FileKind::Image)
        ->and(FileKind::fromMime('application/octet-stream', 'DOCX'))->toBe(FileKind::Document);
});
