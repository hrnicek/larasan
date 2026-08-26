<?php

declare(strict_types=1);

namespace App\Http\Controllers\File;

use App\Domain\File\Actions\AttachFile;
use App\Domain\File\Actions\DetachFile;
use App\Domain\File\Models\Attachment;
use App\Domain\Task\Models\Task;
use App\Http\Controllers\Controller;
use App\Http\Requests\File\StoreAttachmentRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Uploading a file, and getting it back.
 *
 * The download is a controller rather than a URL from the disk. `Storage::url()` on the local
 * disk would hand out an address that answers to anybody holding it — the disk is configured
 * with `serve => true` — and ADR-0007 is explicit that a storage path is never a capability.
 */
class AttachmentController extends Controller
{
    /**
     * What this application is willing to render on its own origin.
     *
     * Narrower than the upload allow-list and narrower on purpose. An inline response is a
     * document served from this domain, so the question is not "is this an image" but "can this
     * run". SVG is the one that cannot be here: it is XML with script in it, and an inline SVG
     * would execute against this origin's cookies. It stays downloadable, like every other type.
     *
     * @var list<string>
     */
    private const INLINE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function store(StoreAttachmentRequest $request, Task $task, AttachFile $attachFile): RedirectResponse
    {
        $upload = $request->file('file');

        $attachFile->handle($task, $this->actor($request), $upload);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('File attached.')]);

        return back();
    }

    /**
     * Reach, not knowledge of an id. Somebody who has the address of an attachment inside a
     * project they were never given is exactly the person this check exists for.
     */
    public function download(Request $request, Attachment $attachment): StreamedResponse
    {
        $subject = $attachment->attachable;

        if (! $subject instanceof Model) {
            abort(404);
        }

        Gate::authorize('view', $subject);

        $file = $attachment->file;

        $disk = Storage::disk($file->disk);

        if (! $disk->exists($file->path)) {
            abort(404);
        }

        // The name people recognise, not the path it was stored under — which is generated and
        // is nobody's business outside this table.
        return $disk->download($file->path, $file->original_name);
    }

    /**
     * The same object, drawn rather than saved.
     *
     * A separate endpoint from `download` because the two differ in the header that matters:
     * this one says `inline`, which is what an `<img>` needs and what a filing cabinet must not
     * say. The authorization is identical — reach, never knowledge of an id.
     *
     * The bytes behind an attachment never change, so the response is cached hard and
     * revalidated against the checksum the file already stores. A board redrawing forty cards
     * asks for forty images and is answered from the cache.
     */
    public function preview(Request $request, Attachment $attachment): Response|StreamedResponse
    {
        $subject = $attachment->attachable;

        if (! $subject instanceof Model) {
            abort(404);
        }

        Gate::authorize('view', $subject);

        $file = $attachment->file;

        if (! in_array($file->mime_type, self::INLINE_MIME_TYPES, true)) {
            abort(404);
        }

        $etag = '"'.$file->checksum.'"';

        if (trim((string) $request->headers->get('If-None-Match')) === $etag) {
            return response('', 304, $this->previewHeaders($file->mime_type, $etag));
        }

        $disk = Storage::disk($file->disk);

        if (! $disk->exists($file->path)) {
            abort(404);
        }

        return $disk->response($file->path, $file->original_name, $this->previewHeaders($file->mime_type, $etag));
    }

    public function destroy(Request $request, Attachment $attachment, DetachFile $detachFile): RedirectResponse
    {
        Gate::authorize('delete', $attachment);

        $detachFile->handle($attachment, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('File removed.')]);

        return back();
    }

    /**
     * The type is the one recorded when the upload was sniffed, never anything the request
     * offered, and `nosniff` stops the browser from making up a different one — between them
     * they are what keeps a text file from being rendered as something else.
     *
     * @return array<string, string>
     */
    private function previewHeaders(string $mimeType, string $etag): array
    {
        return [
            'Content-Type' => $mimeType,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'ETag' => $etag,
        ];
    }
}
