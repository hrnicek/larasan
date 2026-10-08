<?php

declare(strict_types=1);

namespace App\Http\Controllers\File;

use App\Domain\File\Actions\AttachFiles;
use App\Domain\File\Actions\DetachFile;
use App\Domain\File\Actions\MoveAttachment;
use App\Domain\File\Models\Attachment;
use App\Domain\Task\Models\Task;
use App\Http\Controllers\Controller;
use App\Http\Requests\File\MoveAttachmentRequest;
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
 * Files are streamed here rather than linked via `Storage::url()`, so every read is authorized.
 * See ADR-0007.
 */
class AttachmentController extends Controller
{
    /**
     * SVG is excluded: served inline, it would execute script against this origin.
     *
     * @var list<string>
     */
    private const INLINE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function store(StoreAttachmentRequest $request, Task $task, AttachFiles $attachFiles): RedirectResponse
    {
        $uploads = $request->uploads();

        $attachFiles->handle($task, $this->actor($request), $uploads);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('{1} File attached.|[2,*] :count files attached.', count($uploads), [
                'count' => count($uploads),
            ]),
        ]);

        return back();
    }

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

        return $disk->download($file->path, $file->original_name);
    }

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

        $disk = Storage::disk($file->disk);

        $thumbnail = $request->query('size') === 'thumb' ? $file->thumbnail() : null;

        if ($thumbnail !== null && ! $disk->exists($thumbnail['path'])) {
            $thumbnail = null;
        }

        $path = $thumbnail === null ? $file->path : $thumbnail['path'];
        $mimeType = $thumbnail === null ? $file->mime_type : 'image/webp';

        // Distinct validators, so a cached thumbnail never satisfies a request for the original.
        $etag = '"'.$file->checksum.($thumbnail === null ? '' : '-thumb').'"';

        if (trim((string) $request->headers->get('If-None-Match')) === $etag) {
            return response('', 304, $this->previewHeaders($mimeType, $etag));
        }

        if (! $disk->exists($path)) {
            abort(404);
        }

        return $disk->response($path, $file->original_name, $this->previewHeaders($mimeType, $etag));
    }

    public function move(MoveAttachmentRequest $request, Attachment $attachment, MoveAttachment $moveAttachment): RedirectResponse
    {
        $after = $request->string('after')->value();

        $moveAttachment->handle(
            $attachment,
            $this->actor($request),
            $after === '' ? null : Attachment::query()->find($after),
        );

        return back();
    }

    public function destroy(Request $request, Attachment $attachment, DetachFile $detachFile): RedirectResponse
    {
        Gate::authorize('delete', $attachment);

        $detachFile->handle($attachment, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('File removed.')]);

        return back();
    }

    /**
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
