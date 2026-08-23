<?php

declare(strict_types=1);

namespace App\Domain\File\Models;

use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An object somewhere, and what it was called when it arrived.
 *
 * `disk`, `path`, `size` and `checksum` are the Action's to write from the upload itself
 * (ADR-0007). None of them is fillable, because every one of them is a statement about bytes
 * that exist — a payload that could set `path` could point a row at somebody else's object.
 *
 * @property string $id
 * @property string $workspace_id
 * @property int|null $uploaded_by
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property string $extension
 * @property int $size
 * @property string $checksum
 * @property array<string, mixed> $metadata
 * @property-read Workspace $workspace
 */
#[UseFactory(FileFactory::class)]
class File extends Model
{
    /** @use HasFactory<FileFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['original_name'];

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'metadata' => 'array',
        ];
    }
}
