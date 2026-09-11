<?php

declare(strict_types=1);

namespace App\Domain\Comment\Models;

use App\Domain\Comment\Support\Mentions;
use App\Domain\Shared\Html\RichText;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

/**
 * Something somebody said about something.
 *
 * Polymorphic because a comment will hang from more than tasks eventually, and carrying its
 * own `workspace_id` for the same reason: there is no single aggregate to join through
 * (ADR-0005).
 *
 * Soft deletes, and `edited_at` beside them, because a thread has to be able to say what
 * happened to a line rather than quietly presenting a different conversation.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $commentable_type
 * @property string $commentable_id
 * @property int|null $author_id
 * @property string $body
 * @property CarbonImmutable|null $edited_at
 * @property-read Workspace $workspace
 */
#[UseFactory(CommentFactory::class)]
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory, HasUuids, Searchable, SoftDeletes;

    /**
     * What the search engine is told (ADR-0016).
     *
     * The body, and the type of thing it was said on so a screen can ask for the messages on
     * tasks without reading every comment in the workspace. The subject's *key* is not a
     * searchable word — it is here as a filter, which is what `filterableAttributes` in
     * `config/scout.php` says about it.
     *
     * Nothing here decides who may read the comment: `MessageResults` gives it the reach of the
     * task it hangs from.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'workspace_id' => (string) $this->workspace_id,
            'commentable_type' => (string) $this->commentable_type,
            // `@Jana Nováková` rather than the token, so a name finds the comments that mention it.
            'body' => RichText::toPlainText(Mentions::toPlainText($this->body)),
        ];
    }

    /**
     * The body only. The workspace, the subject and the author are decided by the Action from
     * who is asking and what they are asking about, never by a payload.
     */
    protected $fillable = ['body'];

    public function isEdited(): bool
    {
        return $this->edited_at !== null;
    }

    /** @return MorphTo<Model, $this> */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
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
            'edited_at' => 'immutable_datetime',
        ];
    }
}
