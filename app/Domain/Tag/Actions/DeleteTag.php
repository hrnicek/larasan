<?php

declare(strict_types=1);

namespace App\Domain\Tag\Actions;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Tag\Exceptions\TagException;
use App\Domain\Tag\Models\Tag;
use App\Models\User;

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
