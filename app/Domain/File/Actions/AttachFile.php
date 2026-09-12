<?php

declare(strict_types=1);

namespace App\Domain\File\Actions;

use App\Domain\File\Events\FileAttached;
use App\Domain\File\Exceptions\FileException;
use App\Domain\File\Models\Attachable;
use App\Domain\File\Models\Attachment;
use App\Domain\File\Models\File;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

            $type = (string) Relation::getMorphAlias($subject::class);
            $id = (string) $subject->getKey();

            $attachment = new Attachment;

            $attachment->file_id = $file->id;
            $attachment->attachable_type = $type;
            $attachment->attachable_id = $id;
            $attachment->position = SparsePosition::append($this->lastPosition($type, $id));
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

    private function lastPosition(string $type, string $id): ?int
    {
        // Locked so concurrent uploads cannot compute the same slot. The last row rather than
        // max(position), because PostgreSQL rejects FOR UPDATE with an aggregate.
        $last = Attachment::query()
            ->where('attachable_type', $type)
            ->where('attachable_id', $id)
            ->orderByDesc('position')
            ->lockForUpdate()
            ->value('position');

        return $last === null ? null : (int) $last;
    }

    /** Generated rather than derived from the client filename, so an upload cannot choose its path. */
    private function pathFor(Workspace $workspace, Model&Attachable $subject, UploadedFile $upload): string
    {
        $type = (string) Relation::getMorphAlias($subject::class);
        $extension = Str::lower((string) $upload->getClientOriginalExtension());
        $name = (string) Str::uuid7();

        return "workspaces/{$workspace->id}/{$type}/{$name}".($extension === '' ? '' : ".{$extension}");
    }
}
