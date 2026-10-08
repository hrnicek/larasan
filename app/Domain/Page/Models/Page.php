<?php

declare(strict_types=1);

namespace App\Domain\Page\Models;

use App\Domain\Page\Content\PageDocument;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $project_id
 * @property string|null $parent_id
 * @property string $title
 * @property array<string, mixed> $content
 * @property string|null $excerpt
 * @property int $position
 * @property int $version
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property-read Project $project
 */
#[UseFactory(PageFactory::class)]
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory, HasUuids, Searchable, SoftDeletes;

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'workspace_id' => (string) $this->workspace_id,
            'project_id' => (string) $this->project_id,
            'title' => (string) $this->title,
            'text' => PageDocument::toPlainText($this->content),
        ];
    }

    public const POSITION_GAP = SparsePosition::GAP;

    public const UNTITLED = 'Untitled';

    public const MAX_DEPTH = 5;

    /**
     * @var list<string>
     */
    protected $fillable = ['title', 'content', 'excerpt', 'position'];

    /**
     * Mirrors the database default, which a freshly created model does not read back.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['version' => 1];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Page, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Page, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'position' => 'integer',
            'version' => 'integer',
        ];
    }
}
