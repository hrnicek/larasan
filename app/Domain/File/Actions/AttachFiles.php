<?php

declare(strict_types=1);

namespace App\Domain\File\Actions;

use App\Domain\File\Models\Attachable;
use App\Domain\File\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final readonly class AttachFiles
{
    public function __construct(private AttachFile $attachFile) {}

    /**
     * @param  list<UploadedFile>  $uploads
     * @return list<Attachment>
     */
    public function handle(Model&Attachable $subject, User $actor, array $uploads): array
    {
        return DB::transaction(fn (): array => array_map(
            fn (UploadedFile $upload): Attachment => $this->attachFile->handle($subject, $actor, $upload),
            $uploads,
        ));
    }
}
