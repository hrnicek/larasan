<?php

declare(strict_types=1);

namespace App\Domain\Page\Models;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Models\User;
use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A document written inside a project — the brief, the meeting notes, the thing a task links
 * to rather than repeats.
 *
 * What is stored is ProseMirror's document JSON, not markup (ADR-0017). Markup that arrives
 * from a client is markup somebody else's browser will run; a document made of named nodes can
 * be reduced to the vocabulary this application draws before it is written, and drawn back by
 * the same editor that produced it, so nothing is ever handed to `v-html`.
 *
 * There is no `workspace_id` here. A page has one owning aggregate and reaches its tenant
 * through it, the way a section does.
 *
 * @property string $id
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
    use HasFactory, HasUuids, SoftDeletes;

    /** The gap ADR-0009 specifies, defined once in `SparsePosition`. */
    public const POSITION_GAP = SparsePosition::GAP;

    /**
     * What a page is called before anybody has said. Notion's own answer, and the honest one:
     * a document exists the moment it is created, and demanding a title first would make the
     * empty page a form.
     */
    public const UNTITLED = 'Untitled';

    /** How deep a page may sit. Deep enough for any real document, shallow enough to render. */
    public const MAX_DEPTH = 5;

    /**
     * Every column here is decided by an Action from what the actor may do, never handed over
     * by a payload — the content in particular, which is written only after `PageDocument`
     * has reduced it.
     *
     * @var list<string>
     */
    protected $fillable = ['title', 'content', 'excerpt', 'position'];

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
     * The pages written underneath this one, in the order the tree draws them.
     *
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
