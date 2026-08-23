<?php

declare(strict_types=1);

namespace App\Http\Controllers\File;

use App\Domain\File\Actions\AttachFile;
use App\Domain\File\Models\Attachment;
use App\Domain\Task\Models\Task;
use App\Http\Controllers\Controller;
use App\Http\Requests\File\StoreAttachmentRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    private function actor(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : abort(403);
    }
}
