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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final readonly class AttachFile
{
    /** The width of `files.extension`. */
    public const int MAX_EXTENSION_LENGTH = 32;

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

        if ($actor->cannot('attach', $subject)) {
            throw FileException::cannotUpload();
        }

        $disk = (string) config('filesystems.attachments');

        $checksum = (string) hash_file('sha256', $upload->getRealPath());

        $path = $this->pathFor($workspace, $subject, $upload);

        if (Storage::disk($disk)->putFileAs(dirname($path), $upload, basename($path)) === false) {
            throw FileException::couldNotStore();
        }

        try {
            return $this->record($subject, $actor, $upload, $disk, $path, $checksum);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    private function record(
        Model&Attachable $subject,
        User $actor,
        UploadedFile $upload,
        string $disk,
        string $path,
        string $checksum,
    ): Attachment {
        try {
            return $this->insertRows($subject, $actor, $upload, $disk, $path, $checksum);
        } catch (UniqueConstraintViolationException) {
            // A concurrent upload to the same subject took the last slot; the retry reads the new last row.
            return $this->insertRows($subject, $actor, $upload, $disk, $path, $checksum);
        }
    }

    private function insertRows(
        Model&Attachable $subject,
        User $actor,
        UploadedFile $upload,
        string $disk,
        string $path,
        string $checksum,
    ): Attachment {
        return DB::transaction(function () use ($subject, $actor, $upload, $disk, $path, $checksum): Attachment {
            $file = new File(['original_name' => $upload->getClientOriginalName()]);

            $file->workspace_id = $subject->workspaceId();
            $file->uploaded_by = $actor->id;
            $file->disk = $disk;
            $file->path = $path;
            $file->mime_type = (string) $upload->getMimeType();
            $file->extension = $this->extensionOf($upload);
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
                $file->workspace_id,
                $attachment->attachable_type,
                $attachment->attachable_id,
                $actor->id,
            ));

            // An enclosing transaction, such as a batch upload, can still roll these rows back.
            DB::afterRollBack(fn (): bool => Storage::disk($disk)->delete($path));

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
        $extension = $this->extensionOf($upload);
        $name = (string) Str::uuid7();

        return "workspaces/{$workspace->id}/{$type}/{$name}".($extension === '' ? '' : ".{$extension}");
    }

    private function extensionOf(UploadedFile $upload): string
    {
        $extension = Str::lower($upload->getClientOriginalExtension());

        return preg_match('/^[a-z0-9]{1,'.self::MAX_EXTENSION_LENGTH.'}$/', $extension) === 1 ? $extension : '';
    }
}
