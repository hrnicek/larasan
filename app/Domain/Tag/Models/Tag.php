<?php

declare(strict_types=1);

namespace App\Domain\Tag\Models;

use App\Domain\Shared\Casts\AsAccentColor;
use App\Domain\Shared\ValueObjects\AccentColor;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * What a piece of work is about, in one word, for one workspace.
 *
 * The workspace is not fillable: which workspace a tag belongs to is decided by the Action from
 * where the request was made, never by a payload.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property AccentColor|null $color
 * @property-read Workspace $workspace
 */
#[UseFactory(TagFactory::class)]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'color'];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsToMany<Task, $this> */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_tag');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'color' => AsAccentColor::class,
        ];
    }
}
