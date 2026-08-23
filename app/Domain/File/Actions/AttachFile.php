<?php

declare(strict_types=1);

namespace App\Domain\File\Actions;

use App\Domain\File\Events\FileAttached;
use App\Domain\File\Exceptions\FileException;
use App\Domain\File\Models\Attachable;
use App\Domain\File\Models\Attachment;
use App\Domain\File\Models\File;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Put a file somewhere, and say what it belongs to.
 *
 * The two questions Phase 110 established, asked again because they are the same two: the
 * `file.upload` capability in the **subject's** workspace, and the subject's own policy saying
 * the actor can reach it. Attaching a document to something somebody cannot open is putting it
 * where they will never see it.
 *
 * Everything about the object goes through a **disk name** (ADR-0007), never a provider, and
 * the stored path is generated rather than derived from what was uploaded: a path built from a
 * filename is a path the person uploading chooses, and one of them will eventually choose
 * `../`.
 */
final readonly class AttachFile
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Model&Attachable $subject, User $actor, UploadedFile $upload): Attachment
    {
        $workspace = Workspace::query()->findOrFail($subject->workspaceId());

        if (! $workspace->membershipFor($actor)?->allows(Capability::FileUpload)) {
            throw FileException::cannotUpload();
        }

        if ($actor->cannot('view', $subject)) {
            throw FileException::cannotReachSubject();
        }

        $disk = (string) config('filesystems.attachments');

        // Read from the temporary upload rather than from the stored object: the checksum is
        // what arrived, and computing it after a store would only prove the disk can read back
        // what it just wrote.
        $checksum = (string) hash_file('sha256', $upload->getRealPath());

        $path = $this->pathFor($workspace, $subject, $upload);

        if (Storage::disk($disk)->putFileAs(dirname($path), $upload, basename($path)) === false) {
            throw FileException::couldNotStore();
        }

        return DB::transaction(function () use ($subject, $actor, $upload, $workspace, $disk, $path, $checksum): Attachment {
            $file = new File(['original_name' => $upload->getClientOriginalName()]);

            $file->workspace_id = $workspace->id;
            $file->uploaded_by = $actor->id;
            $file->disk = $disk;
            $file->path = $path;
            $file->mime_type = (string) $upload->getMimeType();
            $file->extension = (string) $upload->getClientOriginalExtension();
            $file->size = (int) $upload->getSize();
            $file->checksum = $checksum;
            $file->metadata = [];
            $file->save();

            $attachment = new Attachment;

            $attachment->file_id = $file->id;
            $attachment->attachable_type = (string) Relation::getMorphAlias($subject::class);
            $attachment->attachable_id = (string) $subject->getKey();
            $attachment->save();

            $this->events->dispatch(new FileAttached(
                $file->id,
                $attachment->id,
                $workspace->id,
                $attachment->attachable_type,
                $attachment->attachable_id,
                $actor->id,
            ));

            return $attachment;
        });
    }

    /**
     * Generated, and readable enough to be swept by workspace: the tenant, the subject, and a
     * uuid. The extension is kept because some object stores serve by it, and it is taken from
     * the upload rather than from the name — an upload calling itself `.pdf` does not make it
     * one, and nothing here trusts it beyond this string.
     */
    private function pathFor(Workspace $workspace, Model&Attachable $subject, UploadedFile $upload): string
    {
        $type = (string) Relation::getMorphAlias($subject::class);
        $extension = Str::lower((string) $upload->getClientOriginalExtension());
        $name = (string) Str::uuid7();

        return "workspaces/{$workspace->id}/{$type}/{$name}".($extension === '' ? '' : ".{$extension}");
    }
}
