<?php

declare(strict_types=1);

namespace App\Domain\Tag\Actions;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Tag\Exceptions\TagException;
use App\Domain\Tag\Models\Tag;
use App\Models\User;

/**
 * Remove a word from the workspace's vocabulary.
 *
 * Hard, not soft: a tag carries no history of its own, and the rows that referenced it go with
 * it (the pivot cascades). What is deleted is the label, never the work it was on — the schema
 * test asserts exactly that.
 */
final readonly class DeleteTag
{
    public function handle(Tag $tag, User $actor): void
    {
        if (! $tag->workspace->membershipFor($actor)?->allows(Capability::TagManage)) {
            throw TagException::cannotManageTags();
        }

        $tag->delete();
    }
}
