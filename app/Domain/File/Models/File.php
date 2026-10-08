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

    // Storage columns are never mass-assigned: a writable path could point a row at another object.
    protected $fillable = ['original_name'];

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * @return array{path: string, width: int, height: int}|null
     */
    public function thumbnail(): ?array
    {
        $thumbnail = $this->metadata['thumb'] ?? null;

        if (! is_array($thumbnail) || ! is_string($thumbnail['path'] ?? null)) {
            return null;
        }

        return [
            'path' => $thumbnail['path'],
            'width' => (int) ($thumbnail['width'] ?? 0),
            'height' => (int) ($thumbnail['height'] ?? 0),
        ];
    }

    /**
     * @return array{width: int, height: int}|null
     */
    public function imageDimensions(): ?array
    {
        $width = (int) ($this->metadata['width'] ?? 0);
        $height = (int) ($this->metadata['height'] ?? 0);

        return $width > 0 && $height > 0 ? ['width' => $width, 'height' => $height] : null;
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
