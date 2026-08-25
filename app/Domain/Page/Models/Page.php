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
 * A document written inside a project — the brief, the meeting notes, the thing a task links
 * to rather than repeats.
 *
 * What is stored is ProseMirror's document JSON, not markup (ADR-0017). Markup that arrives
 * from a client is markup somebody else's browser will run; a document made of named nodes can
 * be reduced to the vocabulary this application draws before it is written, and drawn back by
 * the same editor that produced it, so nothing is ever handed to `v-html`.
 *
 * The `workspace_id` is derived from the project and never moves — a project does not change
 * workspace, so neither does a page. It exists because the search index needs the tenant as an
 * attribute of the document rather than as a join (ADR-0016), which is the same reason `tasks`
 * and `comments` carry one.
 *
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
     * What the search engine is told (ADR-0016).
     *
     * The title and the document as words — `PageDocument::toPlainText()` rather than the JSON,
     * because every node name and attribute in a document is a term to a search engine, and an
     * unstripped page makes *paragraph* match everything anybody has written.
     *
     * The workspace and the project are facts about the row, not permissions: what an actor may
     * open is decided by `VisibleProjectsForUser` when the rows are read (`PageResults`).
     *
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

    /**
     * The database has the same default, and that is not enough: a model that was just saved
     * would report `null` for a column it never read back, and the first save from the editor
     * would carry that null into the version comparison.
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
